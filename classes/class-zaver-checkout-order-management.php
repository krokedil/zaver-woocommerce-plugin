<?php
/**
 * Class for handling for handling order management request from within WooCommerce.
 *
 * @package ZCO/Classes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use KrokedilZCODeps\Zaver\SDK\Config\PaymentStatus;
use KrokedilZCODeps\Zaver\SDK\Object\PaymentCaptureRequest;//phpcs:ignore
use KrokedilZCODeps\Zaver\SDK\Object\PaymentStatusResponse;
use KrokedilZCODeps\Zaver\SDK\Utils\Error;
use Zaver\Classes\Helpers\Order;
use Zaver\Helper;
use Zaver\Plugin;
use function Zaver\ZCO;


/**
 * Handle order management in WooCommerce.
 */
class Zaver_Checkout_Order_Management {

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
	 * @var Zaver_Checkout_Order_Management $instance
	 */
	private static $instance;

	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @static
	 * @return Zaver_Checkout_Order_Management The *Singleton* instance.
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
		add_action( 'woocommerce_order_status_completed', array( $this, 'capture_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancel_order' ), 10, 2 );
	}

	/**
	 * Captures the Zaver order that the WooCommerce order corresponds to.
	 *
	 * @throws Error If the Zaver rejects the capture request.
	 *
	 * @param int      $order_id The WooCommerce order id.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return void|WP_Error
	 */
	public function capture_order( $order_id, $order ) {
		try {
			if ( ! Plugin::gateway()->is_chosen_gateway( $order ) || ! Plugin::gateway()->is_order_management_enabled() ) {
				return;
			}

			if ( $order->get_meta( self::CAPTURED ) ) {
				$order->add_order_note( __( 'The Zaver order has already been captured.', 'zco' ) );
				return;
			}

			if ( empty( $order->get_transaction_id() ) ) {
				$order->update_status( 'on-hold', __( 'The order is missing a transaction ID.', 'zco' ) );
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

			if ( ! $this->can_capture( $payment_status ) ) {
				// If the payment status is pending confirmation, print a order note saying why we cant capture. Otherwise we just ignore it.
				if ( PaymentStatus::PENDING_CONFIRMATION === $payment_status->getPaymentStatus() ) {
					$order->update_status( 'on-hold', __( 'The Zaver order cannot be captured while pending confirmation.', 'zco' ) );
				}

				return;
			}

			$request  = new PaymentCaptureRequest(
				array(
					'captureIdempotencyKey' => wp_generate_uuid4(),
					'amount'                => $order->get_total(),
					'currency'              => $order->get_currency(),
					'lineItems'             => Order::get_line_items( $order ),
				)
			);
			$response = Plugin::gateway()->api()->capturePayment( $order->get_transaction_id(), $request );
			$note     = sprintf(
				// translators: the capture amount.
				__( 'The Zaver order has been captured. Captured amount: %1$s.', 'zco' ),
				wc_price( $response->getCapturedAmount(), array( 'currency' => $response->getCurrency() ) ),
			);
			$order->add_order_note( $note );
			$order->update_meta_data( self::CAPTURED, current_time( ' Y-m-d H:i:s' ) );
		} catch ( Exception | Error $e ) {
			$order->update_status( 'on-hold', $e->getMessage() );
			ZCO()->logger()->error(
				sprintf(
					'[OM]: Failed to capture Zaver payment: %s',
					$e->getMessage()
				),
				Helper::add_request_log_context(
					$e,
					array(
						'orderId'   => $order->get_id(),
						'paymentId' => $order->get_transaction_id(),
					)
				)
			);

			throw $e;
		} finally {
			$order->save();
		}
	}

	/**
	 * Cancels the Zaver order that the WooCommerce order corresponds to.
	 *
	 * @param int      $order_id The WooCommerce order id.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return void|WP_Error
	 */
	public function cancel_order( $order_id, $order ) {
		try {
			if ( ! Plugin::gateway()->is_chosen_gateway( $order ) || ! Plugin::gateway()->is_order_management_enabled() ) {
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
				$order->update_status( 'on-hold', __( 'The order is missing a transaction ID.', 'zco' ) );
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
				return;
			}

			Plugin::gateway()->api()->cancelPayment( $order->get_transaction_id() );

			$order->add_order_note( __( 'The Zaver order has been canceled.', 'zco' ) );
			$order->update_meta_data( self::CANCELED, current_time( ' Y-m-d H:i:s' ) );
		} catch ( Exception | Error $e ) {
			$order->update_status( 'on-hold', $e->getMessage() );
			ZCO()->logger()->error(
				sprintf(
					'[OM]: Failed to cancel Zaver payment: %s',
					$e->getMessage()
				),
				Helper::add_request_log_context(
					$e,
					array(
						'orderId'   => $order->get_id(),
						'paymentId' => $order->get_transaction_id(),
					)
				)
			);

			throw $e;
		} finally {
			$order->save();
		}
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
	 * Whether the Zaver order can be refunded.
	 *
	 * @param PaymentStatusResponse $payment_status The Zaver payment status.
	 * @return boolean Whether the Zaver order can be refunded.
	 */
	public function can_refund( $payment_status ) {
		return $payment_status->getAllowedPaymentOperations()->getCanRefund();
	}
}
