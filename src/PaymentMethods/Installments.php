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
class Installments extends BaseGateway {
	public const PAYMENT_METHOD_ID = 'zaver_pay_in_parts';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id                  = self::PAYMENT_METHOD_ID;
		$this->has_fields          = false;
		$this->method_title        = __( 'Zaver Checkout Installments', 'zco' );
		$this->default_title       = __( 'Delbetalning', 'zco' );
		$this->default_description = __( 'Betala över tid', 'zco' );

		parent::__construct();
	}

	/**
	 * The Zaver SDK payment method identifier.
	 *
	 * The WooCommerce gateway id was renamed to "zaver_pay_in_parts" for Kustom Checkout,
	 * so it no longer encodes the Zaver type. Zaver still identifies this method as
	 * "INSTALLMENTS", so we return that explicitly rather than deriving it from the id.
	 *
	 * @return string
	 */
	public function get_zaver_payment_method() {
		return 'installments';
	}
}
