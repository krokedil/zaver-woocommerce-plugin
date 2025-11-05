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
class PayLater extends BaseGateway {
	public const PAYMENT_METHOD_ID = 'zaver_checkout_pay_later';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id                  = self::PAYMENT_METHOD_ID;
		$this->has_fields          = false;
		$this->method_title        = __( 'Zaver Checkout Pay Later', 'zco' );
		$this->default_title       = __( 'Faktura', 'zco' );
		$this->default_description = __( 'Betala senare', 'zco' );

		parent::__construct();
	}
}
