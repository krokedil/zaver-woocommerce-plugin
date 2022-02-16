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

		Hooks::instance();
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
}

Plugin::instance();