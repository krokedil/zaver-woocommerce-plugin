<?php
/**
 * Plugin Name: WooCommerce Extension
 * Plugin URI: 
 * Description: Your extension's description text.
 * Version: 0.0.1
 * Author: Zaver
 * Author URI: https://www.zaver.io/
 * Developer: Webbmaffian
 * Developer URI: https://www.webbmaffian.se/
 * Text Domain: zaver
 * Domain Path: /languages
 *
 * WC requires at least: 6.0.0
 * WC tested up to: 6.1.1
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Zaver;
use Zaver\SDK\Object\PaymentUpdateRequest;
use Zaver\SDK\Config\PaymentStatus;
use Exception;
use WC_Order;

class Plugin {
	const PATH = __DIR__;
	const PAYMENT_METHOD = 'zaver_checkout';

	static public function instance(): self {
		static $instance = null;

		if(is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	static public function gateway(): Checkout_Gateway {
		static $instance = null;

		if(is_null($instance)) {

			// If the class already is loaded, it's most likely through WooCommerce
			if(class_exists(__NAMESPACE__ . '\Checkout_Gateway', false)) {
				$gateways = WC()->payment_gateways()->payment_gateways();

				if(isset($gateways[self::PAYMENT_METHOD])) {
					$instance = $gateways[self::PAYMENT_METHOD];

					return $instance;
				}
			}

			// Don't bother with loading all the gateways otherwise
			$instance = new Checkout_Gateway();
		}

		return $instance;
	}

	private function __construct() {
		require(self::PATH . '/vendor/autoload.php');
		spl_autoload_register([$this, 'autoloader']);

		add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
		add_filter('wc_get_template', [$this, 'get_zaver_checkout_template'], 10, 3);
		add_action('woocommerce_api_zaver_payment_callback', [$this, 'handle_payment_callback']);
		add_action('template_redirect', [$this, 'check_order_received']);
		add_action('woocommerce_order_status_cancelled', [$this, 'cancelled_order'], 10, 2);
	}

	private function autoloader(string $name): void {
		if(strncmp($name, __NAMESPACE__, strlen(__NAMESPACE__)) !== 0) return;
		
		$classname = trim(substr($name, strlen(__NAMESPACE__)), '\\');
		$filename = strtolower(str_replace('_', '-', $classname));
		$path = sprintf('%s/classes/%s.php', self::PATH, $filename);

		if(file_exists($path)) {
			require($path);
		}
	}

	public function register_gateway(array $gateways): array {
		$gateways[] = __NAMESPACE__ . '\Checkout_Gateway';

		return $gateways;
	}

	public function get_zaver_checkout_template(string $template, string $template_name, array $args): string {
		if($template_name === 'checkout/order-receipt.php' && isset($args['order']) && $args['order'] instanceof WC_Order) {

			/** @var WC_Order */
			$order = $args['order'];

			if($order->get_payment_method() === self::PAYMENT_METHOD) {
				return self::PATH . '/templates/checkout.php';
			}
		}

		return $template;
	}

	public function handle_payment_callback(): void {
		try {
			$payment_status = $this->gateway()->receive_payment_callback();
			$meta = $payment_status->getMerchantMetadata();

			if(!isset($meta['orderId'])) {
				throw new Exception('Missing order ID');
			}

			$order = wc_get_order($meta['orderId']);

			if(!$order) {
				throw new Exception('Order not found');
			}

			Helper::handle_payment_response($order, $payment_status, false);
		}
		catch(Exception $e) {
			status_header(400);
		}
	}

	/**
	 * As the Zaver payment callback will only be called for sites over HTTPS,
	 * we need an alternative way for those sites on HTTP. This is it.
	 */
	public function check_order_received(): void {
		/** @var \WP_Query $wp */
		global $wp;

		try {
			// Ensure we're on the correct endpoint
			if(!isset($wp->query_vars['order-received'])) return;

			$order = wc_get_order($wp->query_vars['order-received']);

			// Don't care about orders with other payment methods
			if(!$order || $order->get_payment_method() !== self::PAYMENT_METHOD) return;

			Helper::handle_payment_response($order);
		}
		catch(Exception $e) {
			$order->update_status('failed');
			wp_die(__('An error occured - please try again', 'zco'));
		}
	}

	public function woocommerce_order_status_cancelled(int $order_id, WC_Order $order): void {
		$payment = $order->get_meta('_zaver_payment');

		if(empty($payment) || !is_array($payment) || !isset($payment['id'])) return;

		$update = PaymentUpdateRequest::create()
			->setStatus(PaymentStatus::CANCELLED);

		$this->gateway()->api()->updatePayment($payment['id'], $update);
	}
}

Plugin::instance();