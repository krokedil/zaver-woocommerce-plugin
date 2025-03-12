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
use KrokedilZCODeps\Krokedil\Support\Logger;
use WC_Order;
use WC_Payment_Gateway;
use Exception;

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

		$separate_payment_methods = wc_string_to_bool( $this->get_option( 'separate_methods', false ) );
		if ( $separate_payment_methods ) {
			add_filter( 'wc_get_template', array( $this, 'payment_categories' ), 10, 3 );
		}
	}

	/**
	 * Display the payment categories under the gateway on the checkout page.
	 *
	 * @param string $template Target template file location.
	 * @param string $template_name The name of the template.
	 * @param array  $args Arguments for the template.
	 * @return string
	 */
	public function payment_categories( $template, $template_name, $args ) {
		if ( ! is_checkout() ) {
			return $template;
		}

		if ( ( 'checkout/payment-method.php' !== $template_name ) || ( Plugin::PAYMENT_METHOD !== $args['gateway']->id ) ) {
			return $template;
		}

		return ZCO_PLUGIN_PATH . '/templates/payment-categories.php';
	}

	/**
	 * Check if the gateway is the chosen payment method.
	 *
	 * Due to inconsistencies in the gateway name (e.g., zaver_checkout vs. zaver_checkout_bank_transfer), we need to check for the presence of 'zaver_checkout'.
	 *
	 * @param int|null|\WC_Order $order_id The WooCommerce order or its id.
	 * @return bool
	 */
	public static function is_chosen_gateway( $order_id = null ) {
		if ( $order_id instanceof \WC_Order ) {
			$chosen_gateway = $order_id->get_payment_method();
		} elseif ( ! empty( $order_id ) ) {
			$order          = wc_get_order( $order_id );
			$chosen_gateway = empty( $order ) ? '' : $order->get_payment_method();
		} else {
			$chosen_gateway = ! isset( WC()->session ) ? '' : WC()->session->get( 'chosen_payment_method' );
		}

		return strpos( $chosen_gateway, Plugin::PAYMENT_METHOD ) !== false;
	}

	/**
	 * Initialize the plugin settings (form fields).
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'          => array(
				'type'    => 'checkbox',
				'default' => 'yes',
				'title'   => __( 'Enable/Disable', 'zco' ),
				'label'   => __( 'Enable Zaver Checkout', 'zco' ),
			),
			'title'            => array(
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __( 'Title', 'zco' ),
				'description' => __( 'This controls the title which the user sees during checkout.', 'zco' ),
				'default'     => __( 'Zaver Checkout', 'zco' ),
			),
			'test_mode'        => array(
				'type'        => 'checkbox',
				'default'     => 'no',
				'desc_tip'    => true,
				'title'       => __( 'Test mode', 'zco' ),
				'label'       => __( 'Enable test mode', 'zco' ),
				'description' => __( 'If you received any test credentials from Zaver, this checkbox should be checked.', 'zco' ),
			),
			'api_key'          => array(
				'type'        => 'text',
				'class'       => 'code',
				'desc_tip'    => false,
				'title'       => __( 'API Key', 'zco' ),
				'description' => sprintf(
					// translators: %1$s: Sign up link, %2$s: Contact link.
					__( '%1$s if you don\'t have any account, or %2$s if you miss your API key / callback token.', 'zco' ),
					'<a target="_blank" href="https://zaver.com/woocommerce">' . __( 'Sign up', 'zco' ) . '</a>',
					'<a target="_blank" href="' . esc_attr( __( 'https://zaver.com/en/contact', 'zco' ) ) . '">' . __( 'contact Zaver', 'zco' ) . '</a>',
				),
			),
			'callback_token'   => array(
				'type'        => 'text',
				'class'       => 'code',
				'desc_tip'    => true,
				'title'       => __( 'Callback Token', 'zco' ),
				'description' => __( 'The callback token is optional but recommended - it is used to validate requests from Zaver.', 'zco' ),
			),
			'separate_methods' => array(
				'type'        => 'checkbox',
				'default'     => 'no',
				'desc_tip'    => true,
				'title'       => __( 'Separate payment methods in the checkout', 'zco' ),
				'label'       => __( 'Show Zaver as separate payment methods in the checkout.', 'zco' ),
				'description' => __( 'If you want to show each payment method as a separate gateway, check this box.', 'zco' ),
			),
			'primary_color'    => array(
				'type'        => 'color',
				'desc_tip'    => true,
				'title'       => __( 'Primary color', 'zco' ),
				'description' => __( 'Some elements in the Zaver Checkout will get this color.', 'zco' ),
				'placeholder' => __( 'Default', 'zco' ),
			),
		);

		$this->form_fields = Logger::add_settings_fields( $this->form_fields );
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

		$payment = $order->get_meta( '_zaver_payment' );
		return ! isset( $payment['id'] );
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
	 * Returns the HTML snippet for the Zaver Checkout.
	 *
	 * @param string $token The token to use.
	 * @return string
	 */
	public function get_html_snippet( $token ) {
		$attributes = array();

		$primary_color = $this->get_option( 'primary_color' );
		if ( ! empty( $primary_color ) ) {
			$attributes['zco-primary-color'] = $primary_color;
		}

		return $this->api()->getHtmlSnippet( $token, apply_filters( 'zco_html_snippet_attributes', $attributes, $this ) );
	}

	/**
	 * Receives the payment callback.
	 *
	 * @return PaymentStatusResponse
	 */
	public function receive_payment_callback() {
		return $this->api()->receiveCallback( $this->get_option( 'callback_token' ) );
	}

	/**
	 * Receives the refund callback.
	 *
	 * @return RefundResponse
	 */
	public function receive_refund_callback() {
		return $this->refund_api()->receiveCallback( $this->get_option( 'callback_token' ) );
	}
}
