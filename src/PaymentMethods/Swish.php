<?php
/**
 * The Zaver Checkout payment gateway.
 *
 * @package ZCO/PaymentMethods
 */

namespace Krokedil\Zaver\PaymentMethods;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Zaver Checkout payment gateway.
 */
class Swish extends BaseGateway {

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id                  = 'zaver_checkout_swish';
		$this->has_fields          = false;
		$this->method_title        = __( 'Zaver Checkout Swish', 'zco' );
		$this->default_title       = __( 'Swish', 'zco' );
		$this->default_description = __( 'Perfekt för mindre belopp', 'zco' );

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
}
