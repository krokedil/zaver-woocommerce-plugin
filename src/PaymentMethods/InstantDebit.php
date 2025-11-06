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
class InstantDebit extends BaseGateway {
	public const PAYMENT_METHOD_ID = 'zaver_checkout_instant_debit';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id                  = self::PAYMENT_METHOD_ID;
		$this->has_fields          = false;
		$this->method_title        = __( 'Zaver Checkout Instant Debit', 'zco' );
		$this->default_title       = __( 'Betala nu', 'zco' );
		$this->default_description = __( 'Extra smidigt vid större köp', 'zco' );

		parent::__construct();
	}
}
