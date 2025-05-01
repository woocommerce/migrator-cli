<?php
/**
 * Plugin Name:     Migrate to Woo
 * Description:     CLI commands to migrate data to WooCommerce from other platforms like Shopify.
 * Version:         0.0.1
 *
 * @package         Migrate to Woo
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Load interfaces early for compatibility with external adapter plugins
require_once __DIR__ . '/src/importer-core/interfaces/interface-platform-fetcher.php';
require_once __DIR__ . '/src/importer-core/interfaces/interface-platform-mapper.php';

add_action(
	'cli_init',
	function () {
		// Load classes needed for the command itself
		require_once __DIR__ . '/src/importer-core/class-migrate-cli.php';
		require_once __DIR__ . '/src/importer-core/class-migrate-cli-utils.php';
		require_once __DIR__ . '/src/importer-core/controllers/class-migrate-cli-products-controller.php';
		// Core importer, fetchers, mappers are loaded *by the controller* when needed

		// Register the command under the 'wc' namespace
		WP_CLI::add_command( 'wc migrate', 'Migrate_CLI' );
	}
);
