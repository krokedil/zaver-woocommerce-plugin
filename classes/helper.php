<?php
/**
 * The helper class.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use Exception;
use WC_Order_Item;
use WC_Product;
use WC_Tax;
use WP_Error;
use KrokedilZCODeps\Zaver\SDK\Config\ItemType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Helper
 *
 * Contains helper functions.
 */
class Helper {

	/**
	 * Converts an exception to a WP_Error.
	 *
	 * @param \Exception $e The exception to convert.
	 * @param mixed      $data Additional data to include in the error.
	 * @return \WP_Error The converted error.
	 */
	public static function wp_error( $e, $data = null ) {
		return new WP_Error( $e->getCode() ? $e->getCode() : 'error', $e->getMessage(), $data );
	}

	/**
	 * Get the tax rate for a line item.
	 *
	 * @param WC_Order_Item $item     The line item to get the tax rate for.
	 * @param bool          $is_shipping Whether the item is a shipping item.
	 *
	 * @return float
	 */
	public static function get_line_item_tax_rate( $item, $is_shipping = false ) {
		$order = $item->get_order();
		$args  = array(
			'country'   => $order->get_billing_country(),
			'state'     => $order->get_billing_state(),
			'city'      => $order->get_billing_city(),
			'postcode'  => $order->get_billing_postcode(),
			'tax_class' => $item->get_tax_class(),
		);

		$rates = $is_shipping ? WC_Tax::find_shipping_rates( $args ) : WC_Tax::find_rates( $args );

		if ( empty( $rates ) ) {
			return 0;
		}

		return floatval( end( $rates )['rate'] );
	}

	/**
	 * Get the item type for the Zaver API.
	 *
	 * @param WC_Product $product The product to get the item type for.
	 *
	 * @return string
	 */
	public static function get_zaver_item_type( $product ) {
		return $product->is_virtual() ? ItemType::DIGITAL : ItemType::PHYSICAL;
	}

	/**
	 * Check if the current request is HTTPS.
	 *
	 * @return bool
	 */
	public static function is_https() {
		$url = strtolower( home_url() );

		return strncmp( $url, 'https:', 6 ) === 0;
	}

	/**
	 * Get the order by payment ID.
	 *
	 * @param string $payment_id The payment ID to search for.
	 *
	 * @return \WC_Order|false The order object if found, false otherwise.
	 */
	public static function get_order_by_payment_id( $payment_id ) {
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_zaver_payment_id',
				'meta_value' => $payment_id,
				'limit'      => 1,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'return'     => 'objects',
			)
		);

		$order = reset( $orders );
		if ( empty( $order ) || $order->get_meta( '_zaver_payment_id' ) !== $payment_id ) {
			return false;
		}

		return $order;
	}

	/**
	 * Format a number to two decimals.
	 *
	 * @param float|string $number The number to format.
	 *
	 * @return float
	 */
	public static function format_number( $number ) {
		return floatval( number_format( floatval( $number ), 2, '.', '' ) );
	}
}
