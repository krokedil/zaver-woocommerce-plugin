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
	public const PAYMENT_METHOD_ID = 'zaver_checkout_installments';

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
}
