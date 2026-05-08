<?php
namespace Krokedil\Zaver\Admin;

use Krokedil\Zaver\PaymentMethods\Installments;
use Krokedil\Zaver\PaymentMethods\PayLater;
use KrokedilZCODeps\Zaver\SDK\Object\PaymentStatusResponse;
use Zaver\Helper;
use Zaver\Order_Management;
use Zaver\Plugin;

/**
 * Zaver Checkout order metabox.
 *
 * This file contains the class that handles the order metabox content and display.
 */
class OrderMetabox extends \KrokedilZCODeps\Krokedil\WooCommerce\OrderMetabox {
	/**
	 * If the order can be updated for the gateway.
	 *
	 * @var bool
	 */
	private $can_update_order;

	/**
	 * Class constructor.
	 *
	 * @param string $payment_method_id The payment method ID.
	 * @param bool   $can_update_order   Whether the order can be updated for the gateway. Default true.
	 */
	public function __construct( $payment_method_id, $can_update_order = true ) {
		parent::__construct( $payment_method_id, 'Zaver order data', $payment_method_id );

		$this->can_update_order = $can_update_order;

		add_action( 'init', array( $this, 'set_metabox_title' ) );
		add_action( 'init', array( $this, 'handle_sync_order_action' ), 9999 );

		// Only register the AJAX handler once across all metabox instances.
		static $ajax_registered = false;
		if ( ! $ajax_registered ) {
			add_action( 'wp_ajax_zaver_set_order_management', array( __CLASS__, 'handle_set_order_management' ) );
			$ajax_registered = true;
		}

		$this->scripts[] = 'zaver-metabox';
	}

	/**
	 * Register the metabox assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		parent::register_assets();

		\wp_register_script(
			'zaver-metabox',
			plugin_dir_url( ZCO_MAIN_FILE ) . 'assets/js/metabox.js',
			array( 'jquery' ),
			Plugin::VERSION,
			true
		);
	}

	/**
	 * Maybe localize the script with data.
	 *
	 * @param string $handle The script handle.
	 * @return void
	 */
	protected function maybe_localize_script( $handle ) {
		if ( 'zaver-metabox' !== $handle ) {
			return;
		}

		wp_localize_script(
			'zaver-metabox',
			'zaverMetaboxParams',
			array(
				'orderId' => $this->get_id(),
				'metaboxId' => $this->id,
				'ajax'    => array(
					'setOrderManagement' => array(
						'url'    => admin_url( 'admin-ajax.php' ),
						'action' => 'zaver_set_order_management',
						'nonce'  => wp_create_nonce( 'zaver_set_order_management' ),
					),
				),
			)
		);
	}

	/**
	 * Handle the AJAX request to enable/disable order management for an order.
	 *
	 * @return void
	 */
	public static function handle_set_order_management() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'permission_denied' );
		}

		$nonce    = filter_input( INPUT_POST, 'nonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$order_id = filter_input( INPUT_POST, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		$enabled  = filter_input( INPUT_POST, 'enabled', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		if ( ! wp_verify_nonce( $nonce, 'zaver_set_order_management' ) ) {
			wp_send_json_error( 'bad_nonce' );
		}

		if ( empty( $order_id ) ) {
			wp_send_json_error( 'no_order_id' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'no_order' );
		}

		$enabled = ( 'yes' === $enabled ) ? 'yes' : 'no';
		$order->update_meta_data( Order_Management::ORDER_MANAGEMENT_ENABLED, $enabled );
		$order->save();

		wp_send_json_success();
	}

	/**
	 * Set the metabox title.
	 *
	 * @return void
	 */
	public function set_metabox_title() {
		$this->title = __( 'Zaver order data', 'zco' );
	}

	/**
	 * Handle the sync order action.
	 *
	 * @return void
	 */
	public function handle_sync_order_action() {
		// Ensure the user has permission to manage WooCommerce.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = filter_input( INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS  );
		$order_id = filter_input( INPUT_GET, 'order_id', FILTER_SANITIZE_NUMBER_INT );
		$zaver_payment_id = filter_input( INPUT_GET, 'zaver_payment_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS  );

		// If the action is not for syncing the order, or if either order id or payment id is missing, bail out.
		if ( 'zaver_sync_order' !== $action || empty( $order_id ) || empty( $zaver_payment_id ) ) {
			return;
		}

		// If the nonce is not valid, return the user to the previous page.
		if ( ! check_admin_referer( 'zaver_sync_order' ) ) {
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
			exit;
		}

		$order = wc_get_order( $order_id );
		Plugin::instance()->order_management()->update_order( $order, $zaver_payment_id );

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}

	/**
	 * Render the metabox.
	 *
	 * @param \WP_Post $post The WordPress post.
	 *
	 * @return void
	 */
	public function metabox_content( $post ) {
		try {
			// Get the WC Order from the post.
			$order = ( is_a( $post, \WC_Order::class ) ) ? $post : wc_get_order( $post->ID );

			if ( empty( $order ) ) {
				return;
			}

			$payment_status = Plugin::gateway()->api()->getPaymentStatus( $order->get_transaction_id() );

			// If the general payment method was used, we need to check the payment method from the payment status response to see if the order can be updated.
			if ( Plugin::PAYMENT_METHOD === $this->payment_method_id ) {
				$this->set_can_update_order( $payment_status );
			}

			$price_format_args = array(
				'currency' => $payment_status->getCurrency(),
			);

			do_action( 'zco_before_order_metabox_output', $order, $payment_status, $this->payment_method_id );
			self::output_info( __( 'Payment method', 'zco' ), $order->get_payment_method_title(), $payment_status->getPaymentMethod() );
			self::output_info( __( 'Payment Id', 'zco' ), $payment_status->getPaymentId() );
			self::output_info( __( 'Reference', 'zco' ), $payment_status->getMerchantPaymentReference() );
			self::output_info( __( 'Status', 'zco' ), $payment_status->getPaymentStatus() );

			// Show the valid until only if the order is not captured, canceled or refunded.
			if( ! $order->get_meta( Order_Management::CAPTURED ) && ! $order->get_meta( Order_Management::CANCELED ) && ! $order->get_meta( Order_Management::REFUNDED ) ) {
				self::output_info( __( 'Valid until', 'zco' ), $payment_status->getValidUntil()->format( 'Y-m-d H:i:s' ) );
			}

			self::output_info( __( 'Total amount', 'zco' ), wc_price( $payment_status->getAmount(), $price_format_args ) );

			// If the order has been captured, show the captured amount.
			if ( $payment_status->getCapturedAmount() > 0 ) {
				self::output_info( __( 'Captured amount', 'zco' ), wc_price( $payment_status->getCapturedAmount(), $price_format_args ) );
			}

			// If the order has been refunded, show the refunded amount.
			if ( $payment_status->getRefundedAmount() > 0 ) {
				self::output_info( __( 'Refunded amount', 'zco' ), wc_price( $payment_status->getRefundedAmount(), $price_format_args ) );
			}

			$order_management_disabled = ! Order_Management::is_order_management_enabled( $order );
			if ( $order_management_disabled ) {
				self::output_info( __( 'Order management', 'zco' ), __( 'Disabled', 'zco' ) );
			}

			// Only show the sync order button if the order can be updated using the gateway.
			if ( $this->can_update_order ) {
				self::output_sync_order_button( $order, $payment_status, $order_management_disabled );
			}

			self::output_collapsable_section( 'zaver-advanced', __( 'Advanced', 'zco' ), self::get_advanced_section_content( $order ) );

			do_action( 'zco_after_order_metabox_output', $order, $payment_status, $this->payment_method_id );
		} catch ( \Exception $e ) {
			Plugin::instance()->logger()->error(
				sprintf( 'Zaver error when rendering order metabox: %s', $e->getMessage() ),
				Helper::add_zaver_error_details( $e, array( 'orderId' => $post->ID ) )
			);
			self::output_error( __( 'Failed to retrieve the payment from Zaver.', 'zco' ) );
		}
	}

	/**
	 * Set if the order can be updated or not based on the payment method from the payment status response.
	 *
	 * @param PaymentStatusResponse $payment_status
	 * @return void
	 */
	private function set_can_update_order( $payment_status ) {
		// Right now only PAY_LATER and INSTALLMENTS payment methods support updating the order.
		$valid_payment_methods  = array( 'PAY_LATER', 'INSTALLMENTS' );
		$can_update_order       = \in_array( $payment_status->getPaymentMethod(), $valid_payment_methods, true );

		$this->can_update_order = apply_filters( 'zco_can_update_order', $can_update_order, $payment_status, $this->payment_method_id );
	}

	/**
	 * Create a instance of the order metabox for each payment method.
	 *
	 * @param array<string,string> $payment_methods The payment methods. Key is the class, and value is the payment method ID.
	 * @return void
	 */
	public static function register_for_payment_methods( $payment_methods ) {
		foreach ( $payment_methods as $payment_method_id ) {
			// Only enable update order for specific payment methods.
			$can_update_order = \in_array( $payment_method_id, array( Installments::PAYMENT_METHOD_ID, PayLater::PAYMENT_METHOD_ID, Plugin::PAYMENT_METHOD ), true );
			new self( $payment_method_id, $can_update_order );
		}
	}

	/**
	 * Get the advanced section content (the order management toggle).
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @return string
	 */
	private static function get_advanced_section_content( $order ) {
		$enabled = Order_Management::is_order_management_enabled( $order );
		$value   = $enabled ? 'yes' : 'no';

		$title = __( 'Order management', 'zco' );
		$tip   = __( 'Disable this to turn off the automatic synchronization with Zaver. When disabled, capture and cancel of the Zaver payment have to be done manually from the merchant portal.', 'zco' );

		ob_start();
		self::output_toggle_switch( $title, $enabled, $tip, 'zaver-toggle-order-management', array( 'zaver-order-management' => $value ) );
		return ob_get_clean();
	}

	/**
	 * Output the sync order action button.
	 *
	 * @param \WC_Order             $order The WooCommerce order.
	 * @param PaymentStatusResponse $payment_status The payment status from the Zaver order.
	 * @param bool                  $order_management_disabled Whether order management is disabled for this order.
	 *
	 * @return void
	 */
	private static function output_sync_order_button( $order, $payment_status, $order_management_disabled = false ) {
		// If the order is captured, canceled or refunded, do not show the sync button.
		if( $order->get_meta( Order_Management::CAPTURED ) || $order->get_meta( Order_Management::CANCELED ) || $order->get_meta( Order_Management::REFUNDED ) ) {
			return;
		}

		$query_args = array(
			'action'           => 'zaver_sync_order',
			'order_id'         => $order->get_id(),
			'zaver_payment_id' => $payment_status->getPaymentId(),
		);

		$action_url = wp_nonce_url(
			add_query_arg( $query_args, admin_url( 'admin-ajax.php' ) ),
			'zaver_sync_order'
		);

		$payment_amount = $payment_status->getAmount();
		$order_total    = floatval( $order->get_total() );

		// If the WooCommerce order total is more then the Zaver payment amount, disable the button.
		// And set the notice and button type based the difference in totals.
		$disabled = $order_total > $payment_amount;
		$classes  = ( $order_total === $payment_amount ) ? 'button-secondary' : 'button-primary';

		if ( $order_management_disabled ) {
			$classes .= ' disabled';
		}

		echo '<br/>';
		// If the sync is not disabled, print the action button.
		if( ! $disabled ) {
			self::output_action_button(
				__( 'Sync order with Zaver', 'zco' ),
				$action_url,
				false,
				$classes
			);
		} else {
			// Print the disabled button as a button, since action buttons are links that cant be disabled and add a help tip to explain why.
			self::output_button(
				__( 'Sync order with Zaver', 'zco' ),
				"$classes disabled"
			);
			echo wp_kses_post( wc_help_tip(  __( 'The WooCommerce order total is more than the Zaver payment amount, and it can not be synced with Zaver.', 'zco' ) ) );
		}
	}
}
