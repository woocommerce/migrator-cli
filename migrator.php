<?php
/**
 * Plugin Name:     Migrator
 * Description:     CLI commands to migrate data from Shopify to Woo.
 * Version:         0.1.0
 *
 * @package         Migrator
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Only include the config.php file if exists
if ( file_exists( __DIR__ . '/config.php' ) ) {
	require_once __DIR__ . '/config.php';
}

//require_once __DIR__ . '/includes/class-migrator-cli.php';
require_once __DIR__ . '/includes/class-migrator-cli-utils.php';
require_once __DIR__ . '/includes/class-migrator-cli-order-tags.php';
require_once __DIR__ . '/includes/class-migrator-cli-orders.php';
require_once __DIR__ . '/includes/class-migrator-cli-payment-methods.php';
require_once __DIR__ . '/includes/class-migrator-cli-products.php';
require_once __DIR__ . '/includes/class-migrator-cli-subscriptions.php';

add_action(
	'cli_init',
	function () {
		WP_CLI::add_command( 'migrator', 'Migrator_CLI' );
		WP_CLI::add_command( 'migrator fix_missing_order_tags', 'Migrator_CLI_Order_Tags' );
		WP_CLI::add_command( 'migrator orders', 'Migrator_CLI_Orders' );
	}
);
