<?php
/**
 * The hooks class.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use KrokedilZCODeps\Zaver\SDK\Object\PaymentUpdateRequest;
use KrokedilZCODeps\Zaver\SDK\Config\PaymentStatus;
use Exception;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Hooks
 *
 * Registers the hooks for the plugin.
 */
final class Hooks {

	/**
	 * Get the instance of the hooks.
	 *
	 * @return Hooks
	 */
	public static function instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}


	/**
	 * Class constructor.
	 */
	private function __construct() {
		add_action( 'woocommerce_api_zaver_payment_callback', array( $this, 'handle_payment_callback' ) );
		add_action( 'woocommerce_api_zaver_refund_callback', array( $this, 'handle_refund_callback' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancelled_order' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'check_order_received' ) );
		add_action( 'zco_before_checkout', array( $this, 'add_cancel_link' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_styles' ) );
	}

	/**
	 * Enqueue the checkout styles.
	 *
	 * @return void
	 */
	public function enqueue_checkout_styles() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_register_style( 'zco-checkout', plugin_dir_url( ZCO_MAIN_FILE ) . '/assets/css/checkout.css', array(), Plugin::VERSION );
		wp_enqueue_style( 'zco-checkout' );
	}

	/**
	 * Handle the payment callback from Zaver.
	 *
	 * @throws Exception If the order ID is missing or the order is not found.
	 * @return void
	 */
	public function handle_payment_callback() {
		$order = false;

		try {
			$payment_status = Plugin::gateway()->receive_payment_callback();
			$meta           = $payment_status->getPaymentMetadata();

			ZCO()->logger()->debug( 'Received Zaver payment callback', (array) $payment_status );

			// If the orderId wasn't set in the metadata, something went wrong during the order processing in WC. We'll try to recover from the error by looking up the order by payment ID. However, this missing order ID should be logged as it might indicate something wrong (e.g., Zaver kept sending callbacks about a "canceled" non-existing WC orders which filled the log).
			if ( ! isset( $meta['orderId'] ) ) {
				ZCO()->logger()->notice( 'Missing order ID in payment callback', (array) $payment_status );
			}

			$order = isset( $meta['orderId'] ) && ! empty( $meta['orderId'] ) ? wc_get_order( $meta['orderId'] ) : Helper::get_order_by_payment_id( $payment_status->getPaymentId() );
			if ( empty( $order ) ) {
				throw new Exception( 'Order not found' );
			}

			Payment_Processor::handle_response( $order, $payment_status, false );
		} catch ( Exception $e ) {
			$ctx = array( 'paymentId' => $payment_status->getPaymentId() ?? null );

			if ( $order ) {
				$ctx['orderId'] = $order->get_id();

				// translators: %s is the error message.
				$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
				ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ), $ctx );
			} else {
				ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ), $ctx );
			}

			status_header( 400 );
		}
	}

	/**
	 * As the Zaver payment callback will only be called for sites over HTTPS,
	 * we need an alternative way for those sites on HTTP. This is it.
	 *
	 * @throws Exception If the order ID is missing or the order is not found.
	 * @return void
	 */
	public function check_order_received() {
		try {
			// Ensure we're on the correct endpoint.
			if ( ! is_order_received_page() ) {
				return;
			}

			$order = wc_get_order( get_query_var( 'order-received' ) );

			// Don't care about orders with other payment methods.
			if ( ! $order || strpos( $order->get_payment_method(), Plugin::PAYMENT_METHOD ) === false ) {
				return;
			}

			Payment_Processor::handle_response( $order );
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ), array( 'orderId' => $order->get_id() ) );

			wc_add_notice( __( 'An error occurred with your Zaver payment - please try again, or contact the site support.', 'zco' ), 'error' );

			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Handle the refund callback from Zaver.
	 *
	 * @throws Exception If the order ID is missing or the order is not found.
	 * @return void
	 */
	public function handle_refund_callback() {
		try {
			$refund = Plugin::gateway()->receive_refund_callback();
			$meta   = $refund->getMerchantMetadata();

			ZCO()->logger()->debug( 'Received Zaver refund callback', (array) $refund );

			if ( ! isset( $meta['orderId'] ) ) {
				throw new Exception( 'Missing order ID' );
			}

			$order = wc_get_order( $meta['orderId'] );

			if ( ! $order ) {
				throw new Exception( 'Order not found' );
			}

			Refund_Processor::handle_response( $order, $refund );
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ), array( 'orderId' => $order->get_id() ) );

			status_header( 400 );
		}
	}

	/**
	 * Cancel the Zaver payment when the order is cancelled.
	 *
	 * @throws Exception If the payment ID is missing.
	 *
	 * @param int      $order_id The WooCommerce order ID.
	 * @param WC_Order $order The WooCommerce order.
	 *
	 * @return void
	 */
	public function cancelled_order( $order_id, $order ) {
		$payment = $order->get_meta( '_zaver_payment' )['id'] ?? $order->get_meta( '_zaver_payment_id' );
		if ( empty( $payment ) ) {
			return;
		}

		try {
			$response = Plugin::gateway()->api()->cancelPayment( $payment['id'] );
			$order->add_order_note( __( 'Cancelled Zaver payment', 'zco' ) );

			ZCO()->logger()->info(
				'Cancelled Zaver payment',
				array(
					'payload'   => $payment['id'],
					'response'  => $response,
					'orderId'   => $order->get_id(),
					'paymentId' => $payment['id'],
				)
			);
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->add_order_note( sprintf( __( 'Failed to cancel Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			ZCO()->logger()->error(
				sprintf(
					'Failed to cancel Zaver payment: %s',
					$e->getMessage()
				),
				array(
					'payload'   => $update ?? null,
					'orderId'   => $order->get_id(),
					'paymentId' => $payment['id'],
				)
			);
		}
	}

	/**
	 * Prints a cancel link to the checkout page.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 *
	 * @return void
	 */
	public function add_cancel_link( $order ) {
		$url  = $order->get_cancel_order_url( wc_get_checkout_url() );
		$text = __( 'Change payment method', 'zco' );

		printf( '<p class="zco-cancel-order"><a href="%s">&larr; %s</a></p>', esc_url( $url ), esc_textarea( $text ) );
	}
}
