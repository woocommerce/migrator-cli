<?php

class Migrator_CLI_Utils {

	/**
	 * Checks if Woocommerce is active and if the Shopify tokens are set.
	 */
	public static function health_check() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce is not active. Please install and activate WooCommerce.' );
		}

		if ( ! ACCESS_TOKEN ) {
			WP_CLI::error( 'Missing Shopify access token.' );
		}

		if ( ! SHOPIFY_DOMAIN ) {
			WP_CLI::error( 'Missing Shopify domain.' );
		}
	}

	/**
	 * Disable the sequential orders plugin to prevent problems.
	 */
	public static function disable_sequential_orders() {
		if ( is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'We need to disable WooCommerce Sequential Order Numbers plugin while migration to ensure the order number is set correctly. The plugin will be enabled again after migration finished.' );
			WP_CLI::runcommand( 'plugin deactivate woocommerce-sequential-order-numbers' );
		}
	}

	/**
	 * Enables the sequential orders plugin back.
	 */
	public static function enable_sequential_orders() {
		if ( is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Enabling WooCommerce Sequential Order Numbers plugin.' );
			WP_CLI::runcommand( 'plugin activate woocommerce-sequential-order-numbers' );
		}
	}

	/**
	 * Gets the next rest page link.
	 *
	 * @param array $response
	 * @return string
	 */
	public static function get_rest_next_link( $response ) {
		$links = wp_remote_retrieve_header( $response, 'link' );

		$next_link = '';

		foreach ( explode( ',', $links ) as $link ) {
			if ( strpos( $link, 'rel="next"' ) !== false ) {
				$next_link = str_replace( array( '<', '>; rel="next"' ), '', $link );
				break;
			}
		}

		return $next_link;
	}

	/**
	 * Sets the WP_IMPORTING flag to true to prevent
	 * sending emails and other communications.
	 */
	public static function set_importing_const() {
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}
	}

	/**
	 * Clear in-memory local object cache (global $wp_object_cache) without affecting memcache
	 * and reset in-memory database query log.
	 */
	public static function reset_in_memory_cache() {
		self::reset_local_object_cache();
		self::reset_db_query_log();
	}

	/**
	 * Clears the in-memory WordPress object cache.
	 */
	private static function reset_local_object_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops      = array();
		$wp_object_cache->cache          = array();

		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset(); // important
		}
	}

	/**
	 * Resets the WordPress database query log.
	 */
	private static function reset_db_query_log() {
		global $wpdb;

		$wpdb->queries = array();
	}
}
