<?php
/**
 * The Order class.
 *
 * @package ZCO/Classes/Helpers
 */

namespace Zaver\Classes\Helpers;

use Zaver\SDK\Config\ItemType;
use Zaver\SDK\Object\PaymentCreationRequest;
use Zaver\SDK\Object\MerchantUrls;
use Zaver\SDK\Object\LineItem;
use WC_Order;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use Zaver\Plugin;
use Zaver\Helper;

use function Zaver\ZCO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Order
 *
 * Handles the payment processing.
 */
class Order {

	/**
	 * Process a payment for the given order.
	 *
	 * @param WC_Order $order The order to process the payment for.
	 * @return PaymentCreationRequest
	 */
	public static function create( $order ) {
		$payment = PaymentCreationRequest::create()
			->setMerchantPaymentReference( $order->get_order_number() )
			->setAmount( $order->get_total() )
			->setCurrency( $order->get_currency() )
			->setMarket( $order->get_billing_country() )
			->setMerchantMetadata(
				array(
					'originPlatform' => 'woocommerce',
					'originWebsite'  => home_url(),
					'originPage'     => $order->get_created_via(),
					'customerId'     => (string) $order->get_customer_id(),
					'orderId'        => (string) $order->get_id(),
				)
			)
			->setTitle( self::get_purchase_title( $order ) );

		$merchant_urls = MerchantUrls::create()
			->setSuccessUrl( Plugin::gateway()->get_return_url( $order ) );

		$callback_url = self::get_callback_url( $order );
		if ( ! empty( $callback_url ) ) {
			$merchant_urls->setCallbackUrl( $callback_url );
		}

		$payment->setMerchantUrls( $merchant_urls );

		$types = array( 'line_item', 'shipping', 'fee', 'coupon' );

		foreach ( $order->get_items( $types ) as $item ) {
			$line_item = LineItem::create()
				->setName( $item->get_name() )
				->setQuantity( $item->get_quantity() )
				->setMerchantMetadata( array( 'orderItemId' => $item->get_id() ) );

			if ( $item->is_type( 'line_item' ) ) {
				self::prepare_line_item( $line_item, $item );
			} elseif ( $item->is_type( 'shipping' ) ) {
				self::prepare_shipping( $line_item, $item );
			} elseif ( $item->is_type( 'fee' ) ) {
				self::prepare_fee( $line_item, $item );
			} elseif ( $item->is_type( 'coupon' ) ) {
				self::prepare_coupon( $line_item, $item );
			}

			$payment->addLineItem( $line_item );
		}

		do_action( 'zco_before_process_payment', $payment, $order );
		return $payment;
	}

	/**
	 * Get the title for the purchase.
	 *
	 * @param WC_Order $order The order to get the title for.
	 * @return string
	 */
	private static function get_purchase_title( $order ) {
		$items = $order->get_items();

		// If there's only one order item, return it as title.
		// If there's multiple order items, return a generic title.
		// translators: %s is the order number.
		$title = count( $items ) === 1 ? reset( $items )->get_name() : sprintf( __( 'Order %s', 'zco' ), $order->get_order_number() );

		return apply_filters( 'zco_payment_purchase_title', $title, $order );
	}

	/**
	 * Get the callback URL for the payment.
	 *
	 * @param WC_Order $order The order to get the callback URL for.
	 * @return string|null
	 */
	private static function get_callback_url( $order ) {
		if ( ! Helper::is_https() ) {
			return null;
		}

		return add_query_arg(
			array(
				'wc-api' => 'zaver_payment_callback',
				'key'    => $order->get_order_key(),
			),
			home_url()
		);
	}

	/**
	 * Prepare a line item for the payment.
	 *
	 * @param LineItem              $zaver_item The Zaver line item to prepare.
	 * @param WC_Order_Item_Product $wc_item The WooCommerce line item to prepare.
	 * @return void
	 */
	private static function prepare_line_item( $zaver_item, $wc_item ) {
		$tax         = (float) $wc_item->get_total_tax();
		$total_price = (float) $wc_item->get_total() + $tax;
		$unit_price  = $total_price / $wc_item->get_quantity();
		$product     = $wc_item->get_product();

		$zaver_item
			->setUnitPrice( $unit_price )
			->setTotalAmount( $total_price )
			->setTaxRatePercent( Helper::get_line_item_tax_rate( $wc_item ) )
			->setTaxAmount( $tax )
			->setItemType( Helper::get_zaver_item_type( $product ) )
			->setMerchantReference( $product->get_sku() );

		do_action( 'zco_process_payment_line_item', $zaver_item, $wc_item );
	}

	/**
	 * Prepare a shipping item for the payment.
	 *
	 * @param LineItem               $zaver_item The Zaver line item to prepare.
	 * @param WC_Order_Item_Shipping $wc_item The WooCommerce line item to prepare.
	 * @return void
	 */
	private static function prepare_shipping( $zaver_item, $wc_item ) {
		$tax         = (float) $wc_item->get_total_tax();
		$total_price = (float) $wc_item->get_total() + $tax;
		$unit_price  = $total_price / $wc_item->get_quantity();

		$zaver_item
			->setUnitPrice( $unit_price )
			->setTotalAmount( $total_price )
			->setTaxRatePercent( Helper::get_line_item_tax_rate( $wc_item, true ) )
			->setTaxAmount( $tax )
			->setItemType( ItemType::SHIPPING )
			->setMerchantReference( $wc_item->get_method_id() );

		do_action( 'zco_process_payment_shipping', $zaver_item, $wc_item );
	}

	/**
	 * Prepare a fee item for the payment.
	 *
	 * @param LineItem          $zaver_item The Zaver line item to prepare.
	 * @param WC_Order_Item_Fee $wc_item The WooCommerce line item to prepare.
	 * @return void
	 */
	private static function prepare_fee( $zaver_item, $wc_item ) {
		$tax         = (float) $wc_item->get_total_tax();
		$total_price = (float) $wc_item->get_total() + $tax;
		$unit_price  = $total_price / $wc_item->get_quantity();

		$zaver_item
			->setUnitPrice( $unit_price )
			->setTotalAmount( $total_price )
			->setTaxRatePercent( Helper::get_line_item_tax_rate( $wc_item ) )
			->setTaxAmount( $tax )
			->setItemType( ItemType::FEE );

		do_action( 'zco_process_payment_fee', $zaver_item, $wc_item );
	}

	/**
	 * Prepare a coupon item for the payment.
	 *
	 * @param LineItem             $zaver_item The Zaver line item to prepare.
	 * @param WC_Order_Item_Coupon $wc_item The WooCommerce line item to prepare.
	 * @return void
	 */
	private static function prepare_coupon( $zaver_item, $wc_item ) {
		$tax         = (float) $wc_item->get_discount_tax();
		$total_price = (float) $wc_item->get_discount() + $tax;
		$unit_price  = $total_price / $wc_item->get_quantity();

		$zaver_item
			->setUnitPrice( $unit_price )
			->setTotalAmount( $total_price )
			->setTaxRatePercent( Helper::get_line_item_tax_rate( $wc_item ) )
			->setTaxAmount( $tax )
			->setItemType( ItemType::DISCOUNT );

		do_action( 'zco_process_payment_coupon', $zaver_item, $wc_item );
	}
}
