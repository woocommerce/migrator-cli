<?php

/**
 * Fetches product data from the Shopify API.
 */

// Only include the config.php file if exists
if ( file_exists( __DIR__ . '/config.php' ) ) {
	require_once __DIR__ . '/config.php';
}

class Shopify_Fetcher implements Platform_Fetcher_Interface {

	const SHOPIFY_PRODUCT_QUERY = <<<'GRAPHQL'
	query GetShopifyProducts(
		$first: Int!,
		$after: String,
		$query: String,
		$variantsFirst: Int = 100
	) {
		products(first: $first, after: $after, query: $query) {
			edges {
				cursor
				node {
					id
					title
					handle
					descriptionHtml
					status
					createdAt
					vendor
					tags
					onlineStoreUrl
					options(first: 10) { # Max 3 options usually, 10 is safe
						id
						name
						position
						values
					}
					featuredMedia {
						... on MediaImage {
							id
							image {
								url
								altText
							}
						}
					}
					media(first: 50) {
						edges {
							node {
								... on MediaImage {
									id
									image {
										url
										altText
									}
								}
							}
						}
					}
					variants(first: $variantsFirst) {
						edges {
							node {
								id
								product { id }
								price
								compareAtPrice
								sku
								inventoryPolicy
								inventoryQuantity
								position
								inventoryItem {
									tracked
									measurement {
										weight {
											value
											unit
										}
									}
								}
								media(first: 1) {
									edges {
										node {
											... on MediaImage {
												id
												image {
													url
													altText
												}
											}
										}
									}
								}
								selectedOptions {
									name
									value
								}
							}
						}
					}
					collections(first: 20) {
						edges {
							node {
								id
								handle
								title
							}
						}
					}
					metafields(first: 20, namespace: "global") {
						edges {
							node {
								namespace
								key
								value
							}
						}
					}
				}
			}
			pageInfo {
				hasNextPage
			}
		}
	}
	GRAPHQL;

	/**
	 * Fetches a batch of products from the Shopify GraphQL API.
	 *
	 * @param array $args {
	 *     Arguments for fetching.
	 *     @type int    $limit                Max number of items per batch.
	 *     @type ?string $after_cursor         Cursor for pagination.
	 *     @type string $query_filter         GraphQL query filter string.
	 *     @type int    $variants_per_product Max variants per product.
	 * }
	 * @return array {
	 *      'items' => array Raw product edges fetched from Shopify.
	 *      'cursor' => ?string The cursor for the next page, or null.
	 *      'hasNextPage' => bool Indicates if there are more pages.
	 * }
	 */
	public function fetch_batch( array $args ): array {
		// WP_CLI::line( sprintf( 'Fetching next %d products...', $args['limit'] ) ); // Controller should handle logging

		$variables = array(
			'first' => $args['limit'],
			'after' => $args['after_cursor'] ?? null,
			'query' => $args['query_filter'] ?? '',
			'variantsFirst' => $args['variants_per_product'] ?? 100, // Default added
		);

		$response_data = $this->graphql_request( self::SHOPIFY_PRODUCT_QUERY, $variables );

		if ( is_wp_error( $response_data ) || ! isset( $response_data->products->edges ) ) {
			$error_message = 'Failed to fetch products via GraphQL. ' . ( is_wp_error( $response_data ) ? $response_data->get_error_message() : 'Invalid response structure.' );
			WP_CLI::warning( $error_message ); // Log warning, return empty set
			return [
				'items'       => [],
				'cursor'      => null,
				'hasNextPage' => false,
			];
		}

		$items = $response_data->products->edges;
		$pageInfo = $response_data->products->pageInfo ?? null;
		$last_cursor = null;
		if ( ! empty( $items ) ) {
			$last_edge = end( $items );
			$last_cursor = $last_edge->cursor ?? null;
		}

		// WP_CLI::line( sprintf( 'Successfully fetched %d products.', count( $items ) ) ); // Controller should handle logging

		return [
			'items'       => $items, // Returning the edges array directly
			'cursor'      => $last_cursor,
			'hasNextPage' => $pageInfo ? $pageInfo->hasNextPage : false,
		];
	}

	/**
	 * Fetches the total product count from the Shopify REST API.
	 *
	 * @param array $args Filter arguments (passed to REST API).
	 * @return ?int The total count or null on failure.
	 */
	public function fetch_total_count( array $args ): ?int {
		$rest_api_path = '/products/count.json';
		$query_params = [];

		// Map standard filter args to Shopify REST count query params if needed
		// Example: Map 'status' from GraphQL args to REST API status param
		if ( isset( $args['status'] ) ) {
			$query_params['status'] = strtolower( $args['status'] ); // REST uses lowercase
		}
		if ( isset( $args['before'] ) ) {
			$query_params['created_at_max'] = $args['before'];
		}
		if ( isset( $args['after'] ) ) {
			$query_params['created_at_min'] = $args['after'];
		}
		// Note: Some GraphQL filters (like handle, product_type) might not have direct equivalents
		// in the REST count endpoint or might require different filtering logic.
		// We may need to accept some inaccuracy here or make multiple count calls.

		// If specific IDs are provided, count might not be representative or useful.
		if ( isset( $args['ids'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Calculating total count based on provided IDs.' );
			return count( explode( ',', $args['ids'] ) );
		}

		$response = $this->rest_request( $rest_api_path, $query_params );

		if ( is_wp_error( $response ) || ! isset( $response->count ) ) {
			WP_CLI::warning( 'Could not fetch total product count from Shopify REST API. ' . ( is_wp_error( $response ) ? $response->get_error_message() : 'Unexpected response.' ) );
			return null; // Return null if count cannot be fetched
		}

		return (int) $response->count;
	}


	// --- Private API Helper Methods ---

	/**
	 * Makes a request to the Shopify GraphQL API.
	 * Requires SHOPIFY_API_KEY and SHOPIFY_PASSWORD constants to be defined.
	 *
	 * @param string $query     The GraphQL query string.
	 * @param array  $variables The variables for the query.
	 * @return object|WP_Error Decoded JSON response object or WP_Error on failure.
	 */
	private function graphql_request( string $query, array $variables = [] ) {
		if ( ! defined( 'SHOPIFY_DOMAIN' ) || ! defined( 'ACCESS_TOKEN' ) ) {
			return new WP_Error( 'api_error', 'Shopify API credentials (SHOPIFY_DOMAIN, ACCESS_TOKEN) are not defined.' );
		}

		// Ensure the domain has the protocol
		$domain = SHOPIFY_DOMAIN;
		if ( ! preg_match( '~^https?://~i', $domain ) ) {
			$domain = 'https://' . $domain;
		}

		$shop_url = untrailingslashit( $domain );
		// Use the latest stable API version or make it configurable
		$api_version = '2025-04'; // TODO: Consider making this dynamic or a constant
		$graphql_endpoint = "{$shop_url}/admin/api/{$api_version}/graphql.json";

		$request_args = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'           => 'application/json',
				'X-Shopify-Access-Token' => ACCESS_TOKEN,
			),
			'body'    => wp_json_encode( compact( 'query', 'variables' ) ),
			'timeout' => 60, // Increase timeout for potentially large queries
		);

		$response      = wp_remote_request( $graphql_endpoint, $request_args );
		$response_code = wp_remote_retrieve_response_code( $response );
		$body          = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', 'GraphQL request failed: ' . $response->get_error_message() );
		}

		if ( $response_code >= 300 ) {
			$error_details = json_decode( $body );
			$error_message = isset( $error_details->errors ) ? wp_json_encode( $error_details->errors ) : $body;
			return new WP_Error( 'api_error', "GraphQL request failed with status code {$response_code}: " . $error_message );
		}

		$data = json_decode( $body );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'api_error', 'Failed to decode GraphQL JSON response: ' . json_last_error_msg() );
		}

		if ( ! empty( $data->errors ) ) {
			return new WP_Error( 'graphql_error', 'GraphQL API returned errors: ' . wp_json_encode( $data->errors ) );
		}

		if ( empty( $data->data ) ) {
			return new WP_Error( 'api_error', 'GraphQL response missing "data" field.' );
		}

		return $data->data;
	}

	/**
	 * Makes a request to the Shopify REST API.
	 * Requires SHOPIFY_API_KEY and SHOPIFY_PASSWORD constants to be defined.
	 *
	 * @param string $path         The API path (e.g., '/products/count.json').
	 * @param array  $query_params Optional query parameters.
	 * @param string $method       HTTP method (GET, POST, PUT, DELETE).
	 * @param array  $body         Request body for POST/PUT.
	 * @return object|WP_Error Decoded JSON response object or WP_Error on failure.
	 */
	private function rest_request( string $path, array $query_params = [], string $method = 'GET', array $body = [] ) {
		if ( ! defined( 'SHOPIFY_DOMAIN' ) || ! defined( 'ACCESS_TOKEN' ) ) {
			return new WP_Error( 'api_error', 'Shopify API credentials (SHOPIFY_DOMAIN	, ACCESS_TOKEN) are not defined.' );
		}

		// Ensure the domain has the protocol
		$domain = SHOPIFY_DOMAIN;
		if ( ! preg_match( '~^https?://~i', $domain ) ) {
			$domain = 'https://' . $domain;
		}

		$shop_url = untrailingslashit( $domain );
		// Use the latest stable API version or make it configurable
		$api_version = '2025-04'; // TODO: Consider making this dynamic or a constant
		$rest_endpoint = "{$shop_url}/admin/api/{$api_version}{$path}";

		if ( ! empty( $query_params ) ) {
			$rest_endpoint = add_query_arg( $query_params, $rest_endpoint );
		}

		$request_args = array(
			'method'  => $method,
			'headers' => array(
				'Content-Type'           => 'application/json', // Assume JSON for most REST interactions
				'X-Shopify-Access-Token' => ACCESS_TOKEN,
			),
			'timeout' => 60,
		);

		if ( ! empty( $body ) && ( 'POST' === $method || 'PUT' === $method ) ) {
			$request_args['body'] = wp_json_encode( $body );
		}

		$response      = wp_remote_request( $rest_endpoint, $request_args );
		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', 'REST request failed: ' . $response->get_error_message() );
		}

		if ( $response_code >= 300 ) {
			$error_details = json_decode( $response_body );
			$error_message = isset( $error_details->errors ) ? wp_json_encode( $error_details->errors ) : $response_body;
			return new WP_Error( 'api_error', "REST request to {$path} failed with status code {$response_code}: " . $error_message );
		}

		$data = json_decode( $response_body );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'api_error', 'Failed to decode REST JSON response: ' . json_last_error_msg() );
		}

		return $data; // Return the decoded response object directly
	}

} 