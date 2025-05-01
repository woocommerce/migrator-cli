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

// Load interfaces early for compatibility with external adapter plugins
require_once __DIR__ . '/src/interfaces/interface-platform-fetcher.php';
require_once __DIR__ . '/src/interfaces/interface-platform-mapper.php';

add_action(
	'cli_init',
	function () {
		// Only include the config.php file if exists
		if ( file_exists( __DIR__ . '/config.php' ) ) {
			// require_once __DIR__ . '/config.php';
		}

		// Load classes needed for the command itself
		require_once __DIR__ . '/src/class-migrator-cli.php';
		require_once __DIR__ . '/src/class-migrator-cli-utils.php';
		require_once __DIR__ . '/src/controllers/class-migrator-cli-products.php';
		// Core importer, fetchers, mappers are loaded *by the controller* when needed

		WP_CLI::add_command( 'migrator', 'Migrator_CLI' );
	}
);
