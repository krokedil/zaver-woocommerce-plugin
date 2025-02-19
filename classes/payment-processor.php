<?php
/**
 * The payment processor class.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use Exception;
use Zaver\SDK\Config\ItemType;
use Zaver\SDK\Object\PaymentCreationRequest;
use Zaver\SDK\Object\MerchantUrls;
use Zaver\SDK\Object\LineItem;
use WC_Order;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use Zaver\SDK\Config\PaymentStatus;
use Zaver\SDK\Object\PaymentStatusResponse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Payment_Processor
 *
 * Handles the payment processing.
 */
class Payment_Processor {

	/**
	 * Process a payment for the given order.
	 *
	 * @param WC_Order $order The order to process the payment for.
	 * @return void
	 */
	public static function process( $order ) {
		$payment = Classes\Helpers\Order::create( $order );

		do_action( 'zco_before_process_payment', $payment, $order );
		$response = Plugin::gateway()->api()->createPayment( $payment );

		$order->update_meta_data(
			'_zaver_payment',
			array(
				'id'              => $response->getPaymentId(),
				'token'           => $response->getToken(),
				'tokenValidUntil' => $response->getValidUntil(),
			)
		);

		$order->save_meta_data();

		// Save all line item IDs generated by Zaver.
		foreach ( $response->getLineItems() as $item ) {
			$meta = $item->getMerchantMetadata();

			if ( isset( $meta['orderItemId'] ) ) {
				wc_add_order_item_meta( $meta['orderItemId'], '_zaver_line_item_id', $item->getId(), true );
			}
		}

		ZCO()->logger()->debug(
			'Created Zaver payment request',
			array(
				'orderId'   => $order->get_id(),
				'paymentId' => $response->getPaymentId(),
			)
		);

		do_action( 'zco_after_process_payment', $payment, $order, $response );
	}

	/**
	 * Handle the response from Zaver.
	 *
	 * @throws Exception If the order key is invalid, the payment ID is missing, or the payment ID does not match.
	 *
	 * @param WC_Order                   $order The order to handle the response for.
	 * @param PaymentStatusResponse|null $payment_status The payment status response.
	 * @param bool                       $redirect Whether to redirect the user.
	 * @return void
	 */
	public static function handle_response( $order, $payment_status = null, $redirect = true ) {

		// TODO: I believe what they meant here is to check for if $order->get_date_paid() is not null.
		// Ignore orders that are already paid.
		if ( ! $order->needs_payment() ) {
			return;
		}

		// Ensure that the order key is correct.
		$key = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( ! empty( $key ) || ! hash_equals( $order->get_order_key(), $key ) ) {
			throw new Exception( 'Invalid order key' );
		}

		$payment = $order->get_meta( '_zaver_payment' );
		if ( ! isset( $payment['id'] ) ) {
			throw new Exception( 'Missing payment ID on order' );
		}

		if ( null === $payment_status ) {
			$payment_status = Plugin::gateway()->api()->getPaymentStatus( $payment['id'] );
		} elseif ( $payment_status->getPaymentId() !== $payment['id'] ) {
			throw new Exception( 'Mismatching payment ID' );
		}

		do_action( 'zco_process_payment_handle_response', $order, $payment_status, $redirect );

		switch ( $payment_status->getPaymentStatus() ) {
			case PaymentStatus::SETTLED:
				// translators: %s is the payment ID.
				$order->add_order_note( sprintf( __( 'Successful payment with Zaver - payment ID: %s', 'zco' ), $payment_status->getPaymentId() ) );
				$order->payment_complete( $payment_status->getPaymentId() );
				ZCO()->logger()->info(
					'Successful payment with Zaver',
					array(
						'orderId'   => $order->get_id(),
						'paymentId' => $payment_status->getPaymentId(),
					)
				);
				break;

			case PaymentStatus::CANCELLED:
				ZCO()->logger()->info(
					'Zaver Payment was cancelled',
					array(
						'orderId'   => $order->get_id(),
						'paymentId' => $payment_status->getPaymentId(),
					)
				);

				if ( $redirect ) {
					wp_safe_redirect( $order->get_cancel_order_url() );
					exit;
				}

				$order->update_status( 'cancelled', __( 'Zaver payment was cancelled - cancelling order', 'zco' ) );
				break;

			case PaymentStatus::CREATED:
				ZCO()->logger()->debug(
					'Zaver Payment is still in CREATED state',
					array(
						'orderId'   => $order->get_id(),
						'paymentId' => $payment_status->getPaymentId(),
					)
				);

				if ( $redirect ) {
					wp_safe_redirect( $order->get_checkout_payment_url( true ) );
					exit;
				}

				// Do nothing.
				break;
		}
	}
}
