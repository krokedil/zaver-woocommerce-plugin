<?php
/**
 * This class serves as the foundation for all the Zaver Checkout payment methods.
 *
 * @package ZCO/Classes/PaymentMethods
 */

namespace Krokedil\Zaver\PaymentMethods;

use Zaver\Payment_Processor;
use KrokedilZCODeps\Zaver\SDK\Checkout;
use KrokedilZCODeps\Zaver\SDK\Refund;
use KrokedilZCODeps\Zaver\SDK\Object\PaymentStatusResponse;
use KrokedilZCODeps\Zaver\SDK\Object\RefundResponse;
use WC_Order;
use WC_Payment_Gateway;
use Exception;

use Zaver\Order_Management as OM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Base Zaver Checkout gateway.
 */
abstract class BaseGateway extends WC_Payment_Gateway {

	/**
	 * The Checkout API instance.
	 *
	 * @var Checkout
	 */
	protected $api_instance = null;

	/**
	 * The Refund API instance.
	 *
	 * @var Refund
	 */
	protected $refund_instance = null;

	/**
	 * The default title for the payment method.
	 *
	 * @var string
	 */
	protected $default_title;

	/**
	 * The default description for the payment method.
	 *
	 * @var string
	 */
	protected $default_description;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id           = 'zaver_checkout_bank_transfer';
		$this->has_fields   = false;
		$this->method_title = __( 'Zaver Checkout Bank Transfer', 'zco' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title             = $this->get_option( 'title', $this->method_title );
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
		$default_title       = $this->default_title;
		$default_description = $this->default_description;
		$this->form_fields   = apply_filters( "{$this->id}_settings", include ZCO_PLUGIN_PATH . '/includes/zaver-checkout-settings.php' );
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
		return $this->get_option( 'title' );
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
		return apply_filters( "{$this->id}_is_available", $this->check_availability(), $this );
	}

	/**
	 * Check if the gateway should be available.
	 *
	 * This function is extracted to create the '{$this->id}_is_available' filter.
	 *
	 * @return bool
	 */
	private function check_availability() {
		if ( $this->get_option( 'enabled' ) === 'no' ) {
			return false;
		}

		if ( is_checkout() ) {
			return \Zaver\ZCO()->session()->is_available( $this->id );
		}

		return true;
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
			\Zaver\ZCO()->logger()->error( sprintf( 'Zaver error during payment process: %s', $e->getMessage() ), array( 'orderId' => $order_id ) );

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
		$settings = get_option( 'woocommerce_zaver_checkout_settings', array() );

		// Allow refund to go through if the setting is disabled.
		if ( ! wc_string_to_bool( $settings['order_management_enable_refund'] ?? 'yes' ) ) {
			return true;
		}

		try {
			if ( empty( $amount ) ) {
				throw new Exception( 'No refund amount specified' );
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				throw new Exception( 'Order not found' );
			}

			\Zaver\Refund_Processor::process( $order, (float) $amount );

			return true;
		} catch ( Exception $e ) {
			\Zaver\ZCO()->logger()->error(
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

			return \Zaver\ZCO()->report()->request( \Zaver\Helper::wp_error( $e ) );
		}
	}

	/**
	 * Checks if the gateway can refund a given order.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		return OM::can_refund( $order );
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
		\Zaver\ZCO()->logger()->info( 'Received Zaver payment callback', (array) $callback );
		return $callback;
	}

	/**
	 * Receives the refund callback.
	 *
	 * @return RefundResponse
	 */
	public function receive_refund_callback() {
		$callback = $this->refund_api()->receiveCallback( $this->get_option( 'callback_token' ) );
		\Zaver\ZCO()->logger()->info( 'Received Zaver refund callback', (array) $callback );
		return $callback;
	}
}
