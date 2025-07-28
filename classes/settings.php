<?php
/**
 * Class for Zaver Checkout settings.
 *
 * @package ZCO/Classes
 */
namespace Zaver;

use KrokedilZCODeps\Krokedil\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for plugin settings.
 */
class Settings {

	/**
	 * Returns the settings fields.
	 *
	 * @static
	 * @return array List of filtered setting fields.
	 */
	public static function setting_fields() {
		$settings = array(
			'enabled'                             => array(
				'type'    => 'checkbox',
				'default' => 'yes',
				'title'   => __( 'Enable/Disable', 'zco' ),
				'label'   => __( 'Enable Zaver Checkout', 'zco' ),
			),
			'title'                               => array(
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __( 'Title', 'zco' ),
				'description' => __( 'This controls the title which the user sees during checkout.', 'zco' ),
				'default'     => __( 'Zaver Checkout', 'zco' ),
			),
			'description'                         => array(
				'type'        => 'textarea',
				'desc_tip'    => true,
				'title'       => __( 'Description', 'zco' ),
				'description' => __( 'This controls the description which the user sees during checkout.', 'zco' ),
			),
			'test_mode'                           => array(
				'type'        => 'checkbox',
				'default'     => 'no',
				'desc_tip'    => true,
				'title'       => __( 'Test mode', 'zco' ),
				'label'       => __( 'Enable test mode', 'zco' ),
				'description' => __( 'If you received any test credentials from Zaver, this checkbox should be checked.', 'zco' ),
			),
			'api_key'                             => array(
				'type'        => 'text',
				'class'       => 'code',
				'desc_tip'    => false,
				'title'       => __( 'API Key', 'zco' ),
				'description' => sprintf(
					// translators: %1$s: Sign up link, %2$s: Contact link.
					__( '%1$s if you don\'t have any account, or %2$s if you miss your API key / callback token.', 'zco' ),
					'<a target="_blank" href="https://zaver.com/woocommerce">' . __( 'Sign up', 'zco' ) . '</a>',
					'<a target="_blank" href="' . esc_attr( __( 'https://zaver.com/en/contact', 'zco' ) ) . '">' . __( 'contact Zaver', 'zco' ) . '</a>',
				),
			),
			'callback_token'                      => array(
				'type'        => 'text',
				'class'       => 'code',
				'desc_tip'    => true,
				'title'       => __( 'Callback Token', 'zco' ),
				'description' => __( 'The callback token is optional but recommended - it is used to validate requests from Zaver.', 'zco' ),
			),
			'order_management'                    => array(
				'title'   => __( 'Enable Order Management', 'zco' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Zaver order capture on WooCommerce order completion and Zaver order cancellation on WooCommerce order cancellation', 'zco' ),
				'default' => 'yes',
			),
			'separate_payment_methods_title'      => array(
				'type'  => 'title',
				'title' => __( 'Show as separate payment methods', 'zco' ),
			),
			'enable_payment_method_swish'         => array(
				'title'   => __( 'Swish', 'zco' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable "Swish" payment as separate payment method', 'zco' ),
				'default' => 'yes',
			),
			'enable_payment_method_pay_later'     => array(
				'title'   => __( 'Pay Later', 'zco' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable "Pay Later" payment as separate payment method', 'zco' ),
				'default' => 'yes',
			),
			'enable_payment_method_bank_transfer' => array(
				'title'   => __( 'Bank Transfer', 'zco' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable "Bank Transfer" payment as separate payment method', 'zco' ),
				'default' => 'yes',
			),
			'enable_payment_method_instant_debit' => array(
				'title'   => __( 'Instant Debit', 'zco' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable "Instant Debit" payment as separate payment method', 'zco' ),
				'default' => 'yes',
			),
			'enable_payment_method_installments'  => array(
				'title'   => __( 'Installments', 'zco' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable "Installments" payment as separate payment method', 'zco' ),
				'default' => 'yes',
			),
			'enable_payment_method_vipps'         => array(
				'title'   => __( 'Vipps', 'zco' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable "Vipps" payment as separate payment method', 'zco' ),
				'default' => 'yes',
			),
		);

		$settings = Logger::add_settings_fields( $settings );
		return apply_filters( 'zaver_checkout_settings', $settings );
	}
}
