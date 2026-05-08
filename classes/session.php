<?php
/**
 * The Session class.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use KrokedilZCODeps\Zaver\SDK\Object\PaymentMethodsRequest;

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
		add_action( 'before_woocommerce_pay', array( $this, 'get_session' ), 999999 );

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
		if ( is_order_received_page() ) {
			return;
		}

		$context = $this->get_context();
		if ( null === $context ) {
			return;
		}

		$this->update_available_payment_methods( $context );
	}

	/**
	 * Resolves the total/currency/market context to use when looking up available payment methods.
	 *
	 * On the pay-for-order page the cart is empty, so we read from the order instead.
	 *
	 * @return array{total:string,currency:string,market:string}|null
	 */
	private function get_context() {
		if ( is_checkout_pay_page() ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : false;
			if ( ! $order ) {
				return null;
			}

			$market = $order->get_billing_country();
			return array(
				'total'    => $order->get_total(),
				'currency' => $order->get_currency(),
				'market'   => empty( $market ) ? wc_get_base_location()['country'] : $market,
			);
		}

		if ( ! is_checkout() || ! isset( WC()->cart ) ) {
			return null;
		}

		return array(
			'total'    => WC()->cart->get_total( 'edit' ),
			'currency' => get_woocommerce_currency(),
			'market'   => $this->get_market(),
		);
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
	 * Updates the available Zaver payment methods for the given context, and saves it to the 'zaver_checkout_available_payment_methods' session data.
	 *
	 * @param array{total:string,currency:string,market:string} $context Resolved context.
	 * @return void
	 */
	private function update_available_payment_methods( array $context ) {
		$total    = $context['total'];
		$market   = $context['market'];
		$currency = $context['currency'];

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
				'Received payment methods',
				array(
					'payload'        => wp_json_encode( $payment_methods_request ),
					'paymentMethods' => $payment_methods,
				)
			);

			$available_payment_methods[ $market ][ $currency ][ $total ] = $payment_methods;
			WC()->session->set( 'zaver_checkout_available_payment_methods', $available_payment_methods );
		} catch ( \Exception $e ) {
			\Zaver\ZCO()->logger()->critical(
				'Failed to retrieve payment methods',
				Helper::add_zaver_error_details(
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
	 * Checks whether a Zaver gateway should be available based on the current context (cart or order).
	 *
	 * @param string $id The Zaver payment method identifier (e.g., "PAY_LATER").
	 * @return bool Whether it should be available.
	 */
	public function is_available( $id ) {
		$context = $this->get_context();
		if ( null === $context ) {
			return false;
		}

		$total    = $context['total'];
		$market   = $context['market'];
		$currency = $context['currency'];

		$payment_methods = WC()->session->get( 'zaver_checkout_available_payment_methods' );
		if ( ! isset( $payment_methods[ $market ][ $currency ][ $total ] ) ) {
			$this->update_available_payment_methods( $context );
			$payment_methods = WC()->session->get( 'zaver_checkout_available_payment_methods' );
		}

		$id                    = str_replace( 'zaver_checkout_', '', strtolower( $id ) );
		$zaver_payment_methods = $payment_methods[ $market ][ $currency ][ $total ] ?? array();

		foreach ( $zaver_payment_methods as $payment_method ) {
			$payment_method_id = strtolower( $payment_method['paymentMethod'] ?? '' );
			if ( ! empty( $payment_method_id ) && $payment_method_id === $id ) {
				return true;
			}
		}

		return false;
	}
}
