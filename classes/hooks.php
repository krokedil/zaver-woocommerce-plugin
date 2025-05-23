<?php
/**
 * The hooks class.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use Exception;
use KrokedilZCODeps\Zaver\SDK\Utils\Error;

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
		add_action( 'template_redirect', array( $this, 'check_order_received' ) );
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

			ZCO()->logger()->debug( '[CALLBACK]: Received Zaver payment callback', (array) $payment_status );

			if ( ! isset( $meta['orderId'] ) ) {
				throw new Exception( 'Missing order ID' );
			}

			$order = wc_get_order( $meta['orderId'] );

			if ( ! $order ) {
				throw new Exception( 'Order not found' );
			}

			Payment_Processor::handle_response( $order, $payment_status, false );
		} catch ( Exception | Error $e ) {
			if ( $order ) {
				// translators: %s is the error message.
				$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			}

			ZCO()->logger()->error( sprintf( '[CALLBACK]: Failed with Zaver payment: %s', $e->getMessage() ), Helper::add_request_log_context( $e, array( 'orderId' => $order ? $order->get_id() : null ) ) );

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
		} catch ( Exception | Error $e ) {
			// translators: %s is the error message.
			$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			ZCO()->logger()->error( sprintf( '[ORDER PAY]: Failed with Zaver payment: %s', $e->getMessage() ), Helper::add_request_log_context( $e, array( 'orderId' => $order->get_id() ) ) );

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

			ZCO()->logger()->debug( '[CALLBACK]: Received Zaver refund callback', (array) $refund );

			if ( ! isset( $meta['orderId'] ) ) {
				throw new Exception( 'Missing order ID' );
			}

			$order = wc_get_order( $meta['orderId'] );

			if ( ! $order ) {
				throw new Exception( 'Order not found' );
			}

			Refund_Processor::handle_response( $order, $refund );
		} catch ( Exception | Error $e ) {
			// translators: %s is the error message.
			$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			ZCO()->logger()->error( sprintf( '[CALLBACK]: Failed with Zaver payment: %s', $e->getMessage() ), Helper::add_request_log_context( $e, array( 'orderId' => $order->get_id() ) ) );

			status_header( 400 );
		}
	}
}
