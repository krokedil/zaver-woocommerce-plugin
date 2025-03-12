<?php
/**
 * Plugin Name: Zaver Checkout for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/zaver-checkout-for-woocommerce/
 * Description: The official Zaver Checkout payment gateway for WooCommerce.
 * Version: 0.0.0-dev
 * Author: Zaver
 * Author URI: https://zaver.com/woocommerce
 * Developer: Webbmaffian, Krokedil
 * Developer URI: https://www.webbmaffian.se/
 * Text Domain: zco
 * Domain Path: /languages
 *
 * @package ZCO
 *
 * WC requires at least: 6.0.0
 * WC tested up to: 6.2.1
 *
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Zaver;

use KrokedilZCODeps\Krokedil\Support\Logger;
use KrokedilZCODeps\Krokedil\Support\SystemReport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZCO_MAIN_FILE', __FILE__ );
define( 'ZCO_PLUGIN_PATH', __DIR__ );

/**
 * Class Plugin
 *
 * Handles the plugins initialization.
 */
class Plugin {
	const VERSION        = '0.0.0-dev';
	const PAYMENT_METHOD = 'zaver_checkout';

	/**
	 * The logger instance.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * The system report instance.
	 *
	 * @var SystemReport
	 */
	private $system_report;

	/**
	 * Get the instance of the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance(): self {
		static $instance = null;

		if ( is_null( $instance ) ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Get the logger instance.
	 *
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * Get the system report.
	 *
	 * @return SystemReport
	 */
	public function report() {
		return $this->system_report;
	}

	/**
	 * Get the gateway instance.
	 *
	 * @return Checkout_Gateway
	 */
	public static function gateway() {
		static $instance = null;

		if ( null === $instance ) {

			// If the class already is loaded, it's most likely through WooCommerce.
			if ( class_exists( __NAMESPACE__ . '\Checkout_Gateway', false ) ) {
				$gateways = WC()->payment_gateways()->payment_gateways();

				if ( isset( $gateways[ self::PAYMENT_METHOD ] ) ) {
					$instance = $gateways[ self::PAYMENT_METHOD ];

					return $instance;
				}
			}

			// Don't bother with loading all the gateways otherwise.
			$instance = new Checkout_Gateway();
		}

		return $instance;
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	private function __construct() {
		if ( ! $this->init_composer() ) {
			return;
		}

		$this->include_files();

		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$allow_logging       = wc_string_to_bool( self::gateway()->get_option( 'logging' ) );
		$this->logger        = new Logger( 'zaver_checkout', $allow_logging );
		$this->system_report = new SystemReport( 'zaver_checkout', 'Zaver Checkout' );
		Hooks::instance();
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'zco', false, plugin_basename( __DIR__ ) . '/languages' );
	}

	/**
	 * Initialize composers autoloader.
	 *
	 * @return bool
	 */
	public function init_composer() {
		$autoloader_dependencies        = ZCO_PLUGIN_PATH . '/dependencies/scoper-autoload.php';
		$autoloader_dependencies_result = is_readable( $autoloader_dependencies ) && require $autoloader_dependencies;
		if ( ! $autoloader_dependencies_result ) {
			self::missing_autoloader();
			return false;
		}

		return true;
	}

	/**
	 * Checks if the autoloader is missing and displays an admin notice.
	 *
	 * @return void
	 */
	protected static function missing_autoloader() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore
				esc_html__( 'Your installation of Zaver Checkout is not complete. If you installed this plugin directly from Github please refer to the README.DEV.md file in the plugin.', 'zco' )
			);
		}
		add_action(
			'admin_notices',
			function () {
				?>
					<div class="notice notice-error">
						<p>
						<?php echo esc_html__( 'Your installation of Zaver Checkout is not complete. If you installed this plugin directly from Github please refer to the README.DEV.md file in the plugin.', 'zco' ); ?>
						</p>
					</div>
					<?php
			}
		);
	}

	/**
	 * Include files.
	 */
	public function include_files() {

		// Classes.
		include_once ZCO_PLUGIN_PATH . '/classes/checkout-gateway.php';
		include_once ZCO_PLUGIN_PATH . '/classes/helper.php';
		include_once ZCO_PLUGIN_PATH . '/classes/hooks.php';
		include_once ZCO_PLUGIN_PATH . '/classes/payment-processor.php';
		include_once ZCO_PLUGIN_PATH . '/classes/refund-processor.php';

		include_once ZCO_PLUGIN_PATH . '/classes/helpers/order.php';
		include_once ZCO_PLUGIN_PATH . '/classes/helpers/cart.php';
	}

	/**
	 * Register the gateway.
	 *
	 * @param array $gateways List of registered gateways.
	 *
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		include_once ZCO_PLUGIN_PATH . '/classes/payment-processor.php';
		$gateways[] = __NAMESPACE__ . '\Checkout_Gateway';

		return $gateways;
	}

	/**
	 * Add settings link to plugin page.
	 *
	 * @param array $links List of links.
	 *
	 * @return array
	 */
	public function add_settings_link( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'    => 'wc-settings',
							'tab'     => 'checkout',
							'section' => self::PAYMENT_METHOD,
						),
						admin_url( 'admin.php' )
					)
				),
				__( 'Settings', 'zco' )
			)
		);

		return $links;
	}
}


// phpcs:disable -- This is a global function.

/**
 * Get the instance of the plugin.
 *
 * @return Plugin
 */
function ZCO() {
	return Plugin::instance();
}

ZCO();
