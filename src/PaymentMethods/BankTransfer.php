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
class BankTransfer extends BaseGateway {
	public const PAYMENT_METHOD_ID = 'zaver_checkout_bank_transfer';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id                  = self::PAYMENT_METHOD_ID;
		$this->has_fields          = false;
		$this->method_title        = __( 'Zaver Checkout Bank Transfer', 'zco' );
		$this->default_title       = __( 'Banköverföring', 'zco' );
		$this->default_description = __( 'Pengarna dras från din bank', 'zco' );

		parent::__construct();
	}
}
