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

add_action(
	'cli_init',
	function () {
		// Only include the config.php file if exists
		if ( file_exists( __DIR__ . '/config.php' ) ) {
			require_once __DIR__ . '/config.php';
		}

		require_once __DIR__ . '/src/class-migrator-cli.php';
		require_once __DIR__ . '/src/class-migrator-cli-utils.php';
		require_once __DIR__ . '/src/controllers/class-migrator-cli-products.php';

		WP_CLI::add_command( 'migrator', 'Migrator_CLI' );
	}
);
