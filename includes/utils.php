<?php

class Migrator_CLI_Utils {

	public static function health_check() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}

		if ( ! ACCESS_TOKEN ) {
			WP_CLI::error( 'Missing Shopify access token.' );
		}

		if ( ! SHOPIFY_DOMAIN ) {
			WP_CLI::error( 'Missing Shopify domain.' );
		}
	}

	public static function disable_sequential_orders() {
		if ( is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'We need to disable WooCommerce Sequential Order Numbers plugin while migration to ensure the order number is set correctly. The plugin will be enabled again after migration finished.' );
			WP_CLI::runcommand( 'plugin deactivate woocommerce-sequential-order-numbers' );
		}
	}

	public static function enable_sequential_orders() {
		if ( is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Enabling WooCommerce Sequential Order Numbers plugin.' );
			WP_CLI::runcommand( 'plugin activate woocommerce-sequential-order-numbers' );
		}
	}

	public static function rest_request( $endpoint, $body = array() ) {
		if ( strpos( $endpoint, 'http' ) === false ) {
			$endpoint = 'https://' . SHOPIFY_DOMAIN . '/admin/api/2023-04/' . $endpoint;
		}

		return wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'X-Shopify-Access-Token' => ACCESS_TOKEN,
					'Accept'                 => 'application/json',
				),
				'body'    => array_filter( $body ),
			)
		);
	}

	public function graphql_request( $body ) {
		return wp_remote_post(
			'https://' . SHOPIFY_DOMAIN . '/admin/api/2023-04/graphql.json',
			array(
				'headers' => array(
					'X-Shopify-Access-Token' => ACCESS_TOKEN,
					'Content-Type'           => 'application/json',
				),
				'body'    => json_encode( $body ),
			)
		);
	}

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
}
