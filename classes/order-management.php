<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- Allowing custom file naming convention for this class.
/**
 * Zaver Checkout Order Management Class
 *
 * This file contains the class responsible for handling order management
 * requests from within WooCommerce for the Zaver payment gateway.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use KrokedilZCODeps\Zaver\SDK\Config\PaymentStatus;
use KrokedilZCODeps\Zaver\SDK\Object\PaymentCaptureRequest;
use KrokedilZCODeps\Zaver\SDK\Object\PaymentStatusResponse;
use KrokedilZCODeps\Zaver\SDK\Utils\Error;
use Zaver\Classes\Helpers\Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Zaver\Plugin;

/**
 * Handle order management in WooCommerce.
 */
class Order_Management {

	/** The order has been captured. */
	public const CAPTURED = '_zaver_captured';
	/** The order has been canceled. */
	public const CANCELED = '_zaver_canceled';
	/** The order has been refunded. */
	public const REFUNDED = '_zaver_refunded';
	/** The order has been partially refunded. */
	public const PARTIALLY_REFUNDED = '_zaver_partially_refunded';
	/** The order is on-hold. */
	public const ON_HOLD = '_zaver_on_hold';

	/**
	 * The reference the *Singleton* instance of this class.
	 *
	 * @var Order_Management $instance
	 */
	private static $instance;

	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @static
	 * @return Order_Management The *Singleton* instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$settings = get_option( 'woocommerce_zaver_checkout_settings', array() );

		if ( wc_string_to_bool( $settings['order_management'] ?? 'yes' ) ) {
			add_action( 'woocommerce_order_status_completed', array( $this, 'capture_order' ), 10, 2 );
			add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_order' ), 10, 2 );
		}
	}

	/**
	 * Sets metadata to internally consider the order as captured.
	 *
	 * Allows orders settled immediately or through the merchant portal to be 'Completed' in WooCommerce.
	 *
	 * @param \WC_Order $order The WooCommerce order object.
	 * @return void
	 */
	public static function set_as_captured( $order ) {
		$order->update_meta_data( self::CAPTURED, current_time( ' Y-m-d H:i:s' ) );
		$order->save();
	}

	/**
	 * Captures the Zaver order that the WooCommerce order corresponds to.
	 *
	 * @throws Error If the Zaver rejects the capture request.
	 *
	 * @param int       $order_id The WooCommerce order id.
	 * @param \WC_Order $order The WooCommerce order object.
	 * @return void
	 */
	public function capture_order( $order_id, $order ) {
		if ( ! Plugin::gateway()->is_chosen_gateway( $order ) ) {
			return;
		}

		if ( $order->get_meta( self::CAPTURED ) ) {
			$order->add_order_note( __( 'The Zaver order has already been captured.', 'zco' ) );
			return;
		}

		if ( empty( $order->get_transaction_id() ) ) {
			$note = __( 'The order is missing a transaction ID.', 'zco' );
			$order->update_status( 'on-hold', $note );
			return;
		}

		if ( $order->get_meta( self::CANCELED ) ) {
			$order->add_order_note( __( 'The Zaver order was canceled and can no longer be captured.', 'zco' ) );
			return;
		}

		if ( $order->get_meta( self::REFUNDED ) ) {
			$order->add_order_note( __( 'The Zaver order has been refunded and can no longer be captured.', 'zco' ) );
			return;
		}

		$payment_status = Plugin::gateway()->api()->getPaymentStatus( $order->get_transaction_id() );
		if ( false && ! $this->can_capture( $payment_status ) ) {
			if ( PaymentStatus::PENDING_CONFIRMATION === $payment_status->getPaymentStatus() ) {
				$additional_note = __( ' while pending confirmation.', 'zco' );
			}

			// translators: %s is the additional note.
			$note = sprintf( __( 'The Zaver order cannot be captured%s', 'zco' ), empty( $additional_note ) ? '.' : $additional_note );
			$order->add_order_note( $note );
			return;
		}

		// If the request fails, an ZaverError exception will be thrown. This is caught by WooCommerce which will still complete the status transition, but write an order note about the error, and include the error message from Zaver in that note. Therefore, we don't have to catch the exception here.
		$request  = new PaymentCaptureRequest(
			array(
				'captureIdempotencyKey' => wp_generate_uuid4(),
				'amount'                => $order->get_total(),
				'currency'              => $order->get_currency(),
				'lineItems'             => Order::get_line_items( $order ),
			)
		);
		$response = Plugin::gateway()->api()->capturePayment( $order->get_transaction_id(), $request );

		$note = sprintf(
			// translators: the amount including currency.
			__( 'The Zaver order has been captured. Captured amount: %1$.2f.', 'zco' ),
			self::format_price( $response->getCapturedAmount(), $response->getCurrency() )
		);

		self::set_as_captured( $order );
	}

	/**
	 * Cancels the Zaver order that the WooCommerce order corresponds to.
	 *
	 * @param int       $order_id The WooCommerce order id.
	 * @param \WC_Order $order The WooCommerce order object.
	 * @return void
	 */
	public function cancel_order( $order_id, $order ) {
		if ( ! Plugin::gateway()->is_chosen_gateway( $order ) ) {
			return;
		}

		if ( $order->get_meta( self::CANCELED ) ) {
			$order->add_order_note( __( 'The Zaver order has already been canceled.', 'zco' ) );
			return;
		}

		// The order has not yet been processed.
		if ( empty( $order->get_date_paid() ) ) {
			return;
		}

		if ( empty( $order->get_transaction_id() ) ) {
			$order->add_order_note( __( 'The order is missing a transaction ID.', 'zco' ) );
			$order->update_status( 'on-hold' );
			return;
		}

		if ( $order->get_meta( self::CAPTURED ) ) {
			$order->add_order_note( __( 'The Zaver order has been captured, and can therefore no longer be canceled.', 'zco' ) );
			return;
		}

		if ( $order->get_meta( self::REFUNDED ) ) {
			$order->add_order_note( __( 'The Zaver order has been refunded and can no longer be canceled.', 'zco' ) );
			return;
		}

		$payment_status = Plugin::gateway()->api()->getPaymentStatus( $order->get_transaction_id() );
		if ( ! $this->can_cancel( $payment_status ) ) {
			$order->add_order_note( __( 'The Zaver order cannot be canceled.', 'zco' ) );
			return;
		}

		// If the request fails, an ZaverError exception will be thrown. This is caught by WooCommerce which will still complete the status transition, but write an order note about the error, and include the error message from Zaver in that note. Therefore, we don't have to catch the exception here.
		Plugin::gateway()->api()->cancelPayment( $order->get_transaction_id() );

		$order->add_order_note( __( 'The Zaver order has been canceled.', 'zco' ) );
		$order->update_meta_data( self::CANCELED, current_time( ' Y-m-d H:i:s' ) );
		$order->save();
	}

	/**
	 * Whether the Zaver order can be captured.
	 *
	 * @param PaymentStatusResponse $payment_status The Zaver payment status.
	 * @return boolean Whether the Zaver order can be captured.
	 */
	public function can_capture( $payment_status ) {
		return $payment_status->getAllowedPaymentOperations()->getCanCapture();
	}

	/**
	 * Whether the Zaver order can be canceled.
	 *
	 * @param PaymentStatusResponse $payment_status The Zaver payment status.
	 * @return boolean Whether the Zaver order can be canceled.
	 */
	public function can_cancel( $payment_status ) {
		return $payment_status->getAllowedPaymentOperations()->getCanCancel();
	}

	/**
	 * Check whether the order can be refunded.
	 *
	 * @param \WC_Order             $order The WooCommerce order object.
	 * @param PaymentStatusResponse $payment_status The Zaver payment status.
	 * @return boolean|null Whether the order can be refunded, or `null` if not Zaver order.
	 */
	public static function can_refund( $order, $payment_status = null ) {
		if ( ! Plugin::gateway()->is_chosen_gateway( $order ) ) {
			return null;
		}

		try {
			// If it has not been refunded as indicated by the metadata, issue a request to Zaver to check if it can be refunded.
			$can_refund = empty( $order->get_meta( self::REFUNDED ) );
			if ( $can_refund ) {
				$payment_status = empty( $payment_status ) ? Plugin::gateway()->api()->getPaymentStatus( $order->get_transaction_id() ) : $payment_status;
				$can_refund     = $payment_status->getAllowedPaymentOperations()->getCanRefund();
				if ( ! $can_refund ) {
					$order->update_meta_data( self::REFUNDED, $order->get_meta( '_zaver_refund_id' ) );
					$order->save_meta_data();
				}
			}

			return $can_refund;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Format the price for display.
	 *
	 * @param float|string $amount The amount to format.
	 * @param string       $currency The currency.
	 * @return string The formatted price.
	 */
	public static function format_price( $amount, $currency = '' ) {
		return wc_price( $amount, array( 'currency' => $currency ) );
	}
}

Order_Management::get_instance();
