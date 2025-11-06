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
class Vipps extends BaseGateway {
	public const PAYMENT_METHOD_ID = 'zaver_checkout_vipps';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->id            = self::PAYMENT_METHOD_ID;
		$this->has_fields    = false;
		$this->method_title  = __( 'Zaver Checkout Vipps', 'zco' );
		$this->default_title = __( 'Vipps', 'zco' );

		parent::__construct();
	}
}
