<?php
/**
 * The Zaver Checkout payment gateway.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use KrokedilZCODeps\Zaver\SDK\Checkout;
use KrokedilZCODeps\Zaver\SDK\Refund;
use KrokedilZCODeps\Zaver\SDK\Object\PaymentStatusResponse;
use KrokedilZCODeps\Zaver\SDK\Object\RefundResponse;
use WC_Order;
use WC_Payment_Gateway;
use Exception;
use Zaver\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Zaver Checkout payment gateway.
 */
class Checkout_Gateway extends WC_Payment_Gateway {

	/**
	 * The Checkout API instance.
	 *
	 * @var Checkout
	 */
	private $api_instance = null;

	/**
	 * The Refund API instance.
	 *
	 * @var Refund
	 */
	private $refund_instance = null;

	/**
	 * The gateway subtitle.
	 *
	 * @var string
	 */
	public $subtitle = '';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id           = Plugin::PAYMENT_METHOD;
		$this->has_fields   = false;
		$this->method_title = __( 'Zaver Checkout', 'zco' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title             = $this->get_option( 'title' );
		$this->order_button_text = apply_filters( 'zco_order_button_text', __( 'Pay with Zaver', 'zco' ) );
		$this->supports          = apply_filters(
			$this->id . '_supports',
			array(
				'products',
				'refunds',
			)
		);

		add_action( "woocommerce_update_options_payment_gateways_{$this->id}", array( $this, 'process_admin_options' ) );
	}

	/**
	 * Initialize the plugin settings (form fields).
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = Settings::setting_fields();
	}

	/**
	 * Get the gateway icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon = plugin_dir_url( ZCO_MAIN_FILE ) . 'assets/img/icon.svg';
		return "<img src='{$icon}' style='max-width:120px;' class='zaver-checkout-icon' alt='{$this->title}' />";
	}

	/**
	 * Get the gateway title.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->title;
	}

	/**
	 * Payment method description for the frontend.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->get_option( 'description' );
	}

	/**
	 * Check if payment method should be available.
	 *
	 * @return boolean
	 */
	public function is_available() {
		return apply_filters( 'zaver_checkout_is_available', $this->check_availability(), $this );
	}

	/**
	 * Check if the gateway should be available.
	 *
	 * This function is extracted to create the 'zaver_checkout_is_available' filter.
	 *
	 * @return bool
	 */
	private function check_availability() {
		return $this->get_option( 'enabled' ) === 'yes' && ! ZCO()->separate_payment_methods_enabled();
	}

	/**
	 * Check if the gateway is the chosen payment method.
	 *
	 * Due to inconsistencies in the gateway name, we need to check for the presence of 'zaver_checkout'.
	 *
	 * @param int|null|\WC_Order $order_id The WooCommerce order or its id. If `null`, the current session is used.
	 * @return bool
	 */
	public function is_chosen_gateway( $order_id = null ) {
		if ( $order_id instanceof \WC_Order ) {
			$chosen_gateway = $order_id->get_payment_method();
		} elseif ( ! empty( $order_id ) ) {
			$order          = wc_get_order( $order_id );
			$chosen_gateway = empty( $order ) ? '' : $order->get_payment_method();
		} else {
			$chosen_gateway = ! isset( WC()->session ) ? '' : WC()->session->get( 'chosen_payment_method' );
		}

		return strpos( $chosen_gateway, 'zaver_checkout' ) !== false;
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @throws Exception If the order is not found.
	 *
	 * @param int $order_id WooCommerced order id.
	 * @return array An associative array containing the success status and redirect URL.
	 */
	public function process_payment( $order_id ) {
		try {
			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				throw new Exception( 'Order not found' );
			}

			Payment_Processor::process( $order );

			$redirect_url = $order->get_checkout_payment_url( true );
			if ( ! empty( $order->get_meta( '_zaver_payment_link' ) ) ) {
				$redirect_url = $order->get_meta( '_zaver_payment_link' );
			}

			return apply_filters(
				'zco_process_payment_result',
				array(
					'result'   => 'success',
					'redirect' => $redirect_url,
				)
			);
		} catch ( Exception $e ) {
			ZCO()->logger()->error( sprintf( 'Zaver error during payment process: %s', $e->getMessage() ), array( 'orderId' => $order_id ) );

			$message = __( 'An error occurred - please try again, or contact site support', 'zco' );
			wc_add_notice( $message, 'error' );
			return array(
				'result' => 'error',
			);
		}
	}

	/**
	 * Processes refunds.
	 *
	 * @throws Exception If the refund amount is not specified or order is not found.
	 *
	 * @param int        $order_id The WooCommerce order id.
	 * @param float|null $amount The amount to refund.
	 * @param string     $reason The reason for the refund.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		try {
			if ( empty( $amount ) ) {
				throw new Exception( 'No refund amount specified' );
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				throw new Exception( 'Order not found' );
			}

			Refund_Processor::process( $order, (float) $amount );

			return true;
		} catch ( Exception $e ) {
			ZCO()->logger()->error(
				sprintf(
					'Zaver error during refund process: %s',
					$e->getMessage()
				),
				array(
					'orderId' => $order_id,
					'amount'  => $amount,
					'reason'  => $reason,
				)
			);

			return ZCO()->report()->request( Helper::wp_error( $e ) );
		}
	}

	/**
	 * Checks if the gateway can refund an order.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		if ( ! $order instanceof WC_Order || ! $this->supports( 'refunds' ) ) {
			return false;
		}

		$payment_id = $order->get_meta( '_zaver_payment' )['id'] ?? $order->get_meta( '_zaver_payment_id' );
		return ! empty( $payment_id );
	}


	/**
	 * Returns the Checkout API instance.
	 *
	 * @return Checkout
	 */
	public function api() {
		if ( null === $this->api_instance ) {
			$this->api_instance = new Checkout( $this->get_option( 'api_key', '' ), $this->get_option( 'test_mode' ) === 'yes' );
		}

		return $this->api_instance;
	}

	/**
	 * Returns the Refund API instance.
	 *
	 * @return Refund
	 */
	public function refund_api() {
		if ( is_null( $this->refund_instance ) ) {
			$this->refund_instance = new Refund( $this->get_option( 'api_key', '' ), $this->get_option( 'test_mode' ) === 'yes' );
		}

		return $this->refund_instance;
	}

	/**
	 * Receives the payment callback.
	 *
	 * @return PaymentStatusResponse
	 */
	public function receive_payment_callback() {
		$callback = $this->api()->receiveCallback( $this->get_option( 'callback_token' ) );
		ZCO()->logger()->info( 'Received Zaver payment callback', (array) $callback );
		return $callback;
	}

	/**
	 * Receives the refund callback.
	 *
	 * @return RefundResponse
	 */
	public function receive_refund_callback() {
		$callback = $this->refund_api()->receiveCallback( $this->get_option( 'callback_token' ) );
		ZCO()->logger()->info( 'Received Zaver refund callback', (array) $callback );
		return $callback;
	}
}
