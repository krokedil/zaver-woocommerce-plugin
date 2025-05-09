<?php
/**
 * The Session class.
 *
 * @package ZCO/Classes
 */

namespace Zaver\Classes;

use KrokedilZCODeps\Zaver\SDK\Object\PaymentMethodsRequest;
use KrokedilZCODeps\Zaver\SDK\Utils\Error;
use Zaver\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Session
 *
 * Manages the checkout session.
 */
class Session {

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'get_session' ), 999999 );

		add_action(
			'woocommerce_thankyou',
			function () {
				WC()->session->__unset( 'zaver_checkout_available_payment_methods' );
			}
		);
	}

	/**
	 * Create or update Zaver payment session.
	 */
	public function get_session() {
		if ( ! is_checkout() || is_checkout_pay_page() || is_order_received_page() ) {
			return;
		}

		$this->update_available_payment_methods_from_cart();
	}

	/**
	 * Gets the market from the cart. Defaults to the store's base location.
	 *
	 * @return string The market.
	 */
	private function get_market() {
		$market = WC()->customer->get_billing_country();
		return empty( $market ) ? wc_get_base_location()['country'] : $market;
	}

	/**
	 * Updates the available Zaver payment methods based on the cart content, and saves it to the 'zaver_checkout_available_payment_methods' session data.
	 *
	 * @return void
	 */
	private function update_available_payment_methods_from_cart() {
		$total    = WC()->cart->get_total( 'edit' );
		$market   = $this->get_market();
		$currency = get_woocommerce_currency();

		$available_payment_methods = WC()->session->get( 'zaver_checkout_available_payment_methods' );
		if ( isset( $available_payment_methods[ $market ][ $currency ][ $total ] ) ) {
			return;
		}

		try {
			$payment_methods_request = ( new PaymentMethodsRequest() )
			->setMarket( $market )
			->setAmount( $total )
			->setCurrency( $currency );
			$payment_methods         = \Zaver\Plugin::gateway()->api()->getPaymentMethods( $payment_methods_request )->getPaymentMethods();
			\Zaver\ZCO()->logger()->info(
				'[SESSION]: Received payment methods',
				array(
					'payload'        => wp_json_encode( $payment_methods_request ),
					'paymentMethods' => $payment_methods,
				)
			);

			$available_payment_methods[ $market ][ $currency ][ $total ] = $payment_methods;
			WC()->session->set( 'zaver_checkout_available_payment_methods', $available_payment_methods );
		} catch ( \Exception | Error $e ) {
			\Zaver\ZCO()->logger()->critical(
				'[SESSION]: Failed to retrieve payment methods',
				Helper::add_request_log_context(
					$e,
					array(
						'payload' => array(
							'total'    => $total,
							'market'   => $market,
							'currency' => $currency,
						),
						'code'    => $e->getCode(),
						'message' => $e->getMessage(),
						'trace'   => $e->getTraceAsString(),
					)
				)
			);
		}
	}

	/**
	 * Checks whether a Zaver gateway should be available based on the content of the cart.
	 *
	 * @param string $id The Zaver payment method identifier (e.g., "PAY_LATER").
	 * @return bool Whether it should be available.
	 */
	public function is_available( $id ) {
		if ( ! isset( WC()->cart ) ) {
			return false;
		}

		$total    = WC()->cart->get_total( 'edit' );
		$market   = $this->get_market();
		$currency = get_woocommerce_currency();

		$id              = str_replace( 'zaver_checkout_', '', strtolower( $id ) );
		$payment_methods = WC()->session->get( 'zaver_checkout_available_payment_methods' );
		if ( ! empty( $payment_methods ) ) {
			foreach ( $payment_methods[ $market ][ $currency ][ $total ] as $payment_method ) {
				$payment_method_id = strtolower( $payment_method['paymentMethod'] );
				if ( $payment_method_id === $id ) {
					return true;
				}
			}
		}

		return false;
	}
}
