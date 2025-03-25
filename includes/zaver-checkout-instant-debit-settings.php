<?php
/**
 * Zaver Checkout Instant Debit settings.
 *
 * @package ZCO/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Settings for Zaver Checkout
 */
return apply_filters(
	'zaver_checkout_instant_debit_settings',
	array(
		'enabled'     => array(
			'type'    => 'checkbox',
			'default' => 'yes',
			'title'   => __( 'Enable/Disable', 'zco' ),
			'label'   => __( 'Enable Zaver Checkout', 'zco' ),
		),
		'title'       => array(
			'type'        => 'text',
			'desc_tip'    => true,
			'title'       => __( 'Title', 'zco' ),
			'description' => __( 'This controls the title which the user sees during checkout.', 'zco' ),
			'default'     => __( 'Betala nu', 'zco' ),
		),
		'description' => array(
			'type'        => 'textarea',
			'desc_tip'    => true,
			'title'       => __( 'Description', 'zco' ),
			'description' => __( 'This controls the description which the user sees during checkout.', 'zco' ),
			'default'     => __( 'Extra smidigt vid större köp', 'zco' ),
		),
	)
);
