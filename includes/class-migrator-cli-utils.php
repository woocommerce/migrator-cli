<?php

class Migrator_CLI_Utils {

	/**
	 * Checks if Woocommerce is active and if the Shopify tokens are set.
	 */
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
	 * Executes a rest request to Shopify REST API.
	 *
	 * @param string $endpoint the endpoint.
	 * @param array $body the request body.
	 * @return object
	 */
	public static function rest_request( $endpoint, $body = array() ) {
		$retrying = 0;

		do {
			if ( strpos( $endpoint, 'http' ) === false ) {
				$endpoint = 'https://' . SHOPIFY_DOMAIN . '/admin/api/2023-04/' . $endpoint;
			}

			$response = wp_remote_get(
				$endpoint,
				array(
					'headers' => array(
						'X-Shopify-Access-Token' => ACCESS_TOKEN,
						'Accept'                 => 'application/json',
					),
					'body'    => array_filter( $body ),
				)
			);

			if ( isset( $response->errors ) ) {
				if ( $retrying > 10 ) {
					WP_CLI::error( 'Too many api failures. Stopping: ' . wp_json_encode( $response->errors ) );
					return;
				}

				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Api error trying again: ' . wp_json_encode( $response->errors ) );
				++$retrying;
				sleep( 10 );
				continue;
			}

			$response_data = json_decode( wp_remote_retrieve_body( $response ) );

			if ( isset( $response_data->errors ) ) {
				if ( $retrying > 10 ) {
					WP_CLI::error( 'Too many api errors. Stopping: ' . wp_json_encode( $response_data->errors ) );
					return;
				}

				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Api error trying again: ' . wp_json_encode( $response_data->errors ) );
				++$retrying;
				sleep( 10 );
				continue;
			}

			return ( object ) array(
				'next_link' => self::get_rest_next_link( $response ),
				'data' => $response_data,
			);
		} while ( true );
	}

	/**
	 * Executes a request to the Shopify GraphQL API
	 *
	 * @param string $query The GraphQL query string.
	 * @param array  $variables Optional. Variables for the query.
	 * @return object|WP_Error Decoded data object on success, WP_Error on failure.
	 */
	public static function graphql_request( $query, $variables = array() ) {
		$max_retries = 10;
		$retry_count = 0;

		$body = array(
			'query' => $query,
			'variables' => $variables,
		);

		while ($retry_count <= $max_retries) {
			$response = wp_remote_post(
				'https://' . SHOPIFY_DOMAIN . '/admin/api/2023-04/graphql.json',
				array(
					'headers' => array(
						'X-Shopify-Access-Token' => ACCESS_TOKEN,
						'Content-Type'           => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
					'timeout' => 30,
				)
			);

			// Check if response is an error
			if (is_wp_error($response)) {
				$retry_count++;
				WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'GraphQL wp_remote_post error: ' . $response->get_error_message() . ' (Retry ' . $retry_count . '/' . $max_retries . ')' );

				if ($retry_count > $max_retries) {
					WP_CLI::error('Maximum GraphQL API retries reached after wp_remote_post error.');
					return $response;
				}
				sleep(5);
				continue;
			}

			// Check for rate limiting (status code 429)
			$status_code = wp_remote_retrieve_response_code($response);
			if ($status_code == 429) {
				$retry_count++;
				WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'GraphQL API rate limit reached (429). Retrying... (' . $retry_count . '/' . $max_retries . ')' );

				if ($retry_count > $max_retries) {
					WP_CLI::error('Maximum GraphQL API retries reached after rate limiting.');
					return new WP_Error('rate_limit', 'Exceeded retry limit after rate limiting', array('status' => $status_code));
				}
				sleep(10);
				continue;
			}

			// Check response body for errors
			$response_body_raw = wp_remote_retrieve_body($response);
			$response_body_decoded = json_decode($response_body_raw);

			if (json_last_error() !== JSON_ERROR_NONE) {
			    WP_CLI::warning( 'GraphQL response body is not valid JSON: ' . $response_body_raw );
			    if ($status_code >= 500) {
			        $retry_count++;
			        WP_CLI::line('Retrying due to invalid JSON response (Status: ' . $status_code . ')... (' . $retry_count . '/' . $max_retries . ')');
			        if ($retry_count > $max_retries) {
			            WP_CLI::error('Maximum GraphQL API retries reached after invalid JSON response.');
			            return new WP_Error('invalid_json', 'Invalid JSON response after retries', array('status' => $status_code, 'body' => $response_body_raw));
                    }
                    sleep(5);
                    continue;
                } else {
                    return new WP_Error('invalid_json', 'Invalid JSON response', array('status' => $status_code, 'body' => $response_body_raw));
                }
			}

			if (isset($response_body_decoded->errors)) {
				$error_message = 'GraphQL API returned errors: ' . json_encode( $response_body_decoded->errors );
				WP_CLI::warning( $error_message );
				
				if ($status_code >= 500 || strpos(json_encode($response_body_decoded->errors), 'Internal server error') !== false) {
				    $retry_count++;
				    WP_CLI::line('Retrying due to server-side GraphQL errors... (' . $retry_count . '/' . $max_retries . ')');
				    if ($retry_count > $max_retries) {
                        WP_CLI::error('Maximum GraphQL API retries reached after server-side errors.');
                        return new WP_Error('graphql_server_error', $error_message, array('status' => $status_code, 'response' => $response_body_decoded));
                    }
                    sleep(5);
                    continue;
				} else {
				    return new WP_Error('graphql_error', $error_message, array('status' => $status_code, 'response' => $response_body_decoded));
				}
			}

			if (!isset($response_body_decoded->data)) {
			    $error_message = 'GraphQL response missing "data" field.';
			    WP_CLI::warning( $error_message . ' Response: ' . $response_body_raw );
                return new WP_Error('graphql_missing_data', $error_message, array('status' => $status_code, 'response' => $response_body_decoded));
            }

			return $response_body_decoded->data;
		}

		// If we get here, we've exceeded our retry limit
		WP_CLI::error('GraphQL API request failed after ' . $max_retries . ' retries');
		if (isset($response) && is_wp_error($response)) return $response;
		if (isset($status_code)) return new WP_Error('max_retries_exceeded', 'Max retries exceeded', array('status' => $status_code));
		return new WP_Error('max_retries_exceeded', 'Max retries exceeded');
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
	 * Reset the local WordPress object cache
	 *
	 * This only cleans the local cache in WP_Object_Cache, without
	 * affecting memcache
	 */
	private static function reset_local_object_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops      = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache          = array();

		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset(); // important
		}
	}

	/**
	 * Reset the WordPress DB query log
	 */
	private static function reset_db_query_log() {
		global $wpdb;

		$wpdb->queries = array();
	}

	/**
	 * Returns Shopify store currency.
	 *
	 * @return string Currency code.
	 */
	public static function get_store_currency() {
		$response_data = self::rest_request( 'shop.json' );
		return $response_data->shop->currency;
	}

	/**
	 * Handles the scheduled action for uploading a single product image.
	 *
	 * @param int    $product_id     WooCommerce Product ID.
	 * @param string $image_gql_id   Shopify GraphQL Image ID.
	 * @param string $image_url      Shopify Image URL.
	 * @param string $image_alt_text Shopify Image Alt Text.
	 */
	public static function handle_scheduled_image_upload( $product_id, $image_gql_id, $image_url, $image_alt_text ) {
	    WP_CLI::debug( sprintf( "Action Scheduler: Handling image upload for product %d, image GQL ID %s", $product_id, $image_gql_id ) );

	    $product = wc_get_product( $product_id );
	    if ( ! $product ) {
	        WP_CLI::warning( sprintf( "Action Scheduler: Product %d not found for image %s. Cannot process upload.", $product_id, $image_gql_id ) );
	        return; // Or throw exception? Returning stops the action.
	    }

	    // Check if already mapped (in case action runs twice or was handled manually)
	    $migration_data = $product->get_meta( '_migration_data', true ); // Get single value
	    if ( ! empty( $migration_data['images_mapping'][ $image_gql_id ] ) && wp_attachment_is_image( $migration_data['images_mapping'][ $image_gql_id ] ) ) {
            WP_CLI::debug( sprintf( "Action Scheduler: Image %s already mapped for product %d. Skipping.", $image_gql_id, $product_id ) );
            return;
        }

	    WP_CLI::debug( sprintf( "Action Scheduler: Uploading image %s for product %d from %s...", $image_gql_id, $product_id, $image_url ) );
	    $start_time = microtime(true);
	    $attachment_id = media_sideload_image( $image_url, $product_id, $image_alt_text, 'id' );
	    $duration = microtime(true) - $start_time;

	    if ( is_wp_error( $attachment_id ) ) {
	        WP_CLI::warning( sprintf( "Action Scheduler: Failed to upload image %s for product %d. Error: %s (Duration: %.2f s)", $image_gql_id, $product_id, $attachment_id->get_error_message(), $duration ) );
	    } else {
	        WP_CLI::debug( sprintf( "Action Scheduler: Successfully uploaded image %s for product %d. Attachment ID: %d (Duration: %.2f s)", $image_gql_id, $product_id, $attachment_id, $duration ) );

	        // Update the mapping in product meta
	        // Re-fetch migration data to avoid stale data if multiple actions run close together
	        $migration_data = $product->get_meta( '_migration_data', true );
	        if ( ! is_array( $migration_data ) ) {
	            $migration_data = array(); // Initialize if meta doesn't exist or is not an array
	        }
	        if ( ! isset( $migration_data['images_mapping'] ) || ! is_array( $migration_data['images_mapping'] ) ) {
                $migration_data['images_mapping'] = array();
            }
	        $migration_data['images_mapping'][ $image_gql_id ] = $attachment_id;

	        if ( $product->update_meta_data( '_migration_data', $migration_data ) ) {
	             WP_CLI::debug( sprintf( "Action Scheduler: Updated image mapping for product %d.", $product_id ) );

	             // --- Finalization Logic --- 
	             // Check if all scheduled images for this product are now mapped
                 $total_scheduled = isset($migration_data['total_images_to_schedule']) ? (int) $migration_data['total_images_to_schedule'] : 0;
                 $currently_mapped = count($migration_data['images_mapping']);

                 WP_CLI::debug( sprintf( "Action Scheduler: Product %d - Mapped %d / %d scheduled images.", $product_id, $currently_mapped, $total_scheduled ) );

                 if ($total_scheduled > 0 && $currently_mapped >= $total_scheduled) {
                     WP_CLI::line( sprintf( "Action Scheduler: All %d images processed for product %d. Finalizing assignment...", $total_scheduled, $product_id ) );
                     
                     $featured_image_gql_id = isset($migration_data['featured_image_gql_id']) ? $migration_data['featured_image_gql_id'] : null;
                     $images_mapping = $migration_data['images_mapping'];
                     $needs_save = false;

                     // Set Featured Image
                     $new_featured_id = 0;
                     if ( $featured_image_gql_id && isset( $images_mapping[ $featured_image_gql_id ] ) ) {
                         $new_featured_id = (int) $images_mapping[ $featured_image_gql_id ];
                     }
                     if ($new_featured_id !== (int) $product->get_image_id()) {
                        WP_CLI::debug( "Action Scheduler: Setting featured image for product {$product_id} to {$new_featured_id}." );
                        $product->set_image_id( $new_featured_id );
                        $needs_save = true;
                     }

                     // Set Gallery Images
                     $new_gallery_ids = array();
                     foreach ( $images_mapping as $gql_id => $wp_id ) {
                         if ( $gql_id !== $featured_image_gql_id && $wp_id && wp_attachment_is_image($wp_id) ) {
                             $new_gallery_ids[] = (int) $wp_id;
                         }
                     }
                     $current_gallery_ids = $product->get_gallery_image_ids();
                     sort($new_gallery_ids);
                     sort($current_gallery_ids);
                     if ( $new_gallery_ids !== $current_gallery_ids ) {
                         WP_CLI::debug( "Action Scheduler: Setting gallery images for product {$product_id}." );
                         $product->set_gallery_image_ids( $new_gallery_ids );
                         $needs_save = true;
                     }

                     // Clean up temporary meta keys
                     unset($migration_data['total_images_to_schedule']);
                     unset($migration_data['featured_image_gql_id']);
                     if ($product->update_meta_data( '_migration_data', $migration_data )) {
                         WP_CLI::debug( "Action Scheduler: Cleaned up temporary keys in migration data for product {$product_id}." );
                     } else {
                         WP_CLI::warning( "Action Scheduler: Failed to clean up temporary keys in migration data for product {$product_id}." );
                     }

                     // Save product if changes were made
                     if ($needs_save) {
                         if ($product->save()) {
                             WP_CLI::debug( "Action Scheduler: Saved product {$product_id} after finalizing image assignments." );
                         } else {
                             WP_CLI::warning( "Action Scheduler: Failed to save product {$product_id} after finalizing image assignments." );
                         }
                     } else {
                        WP_CLI::debug( "Action Scheduler: No changes needed for featured/gallery images on product {$product_id}." );
                     }
                 }
	        } else {
	             WP_CLI::warning( sprintf( "Action Scheduler: Failed to update image mapping meta for product %d.", $product_id ) );
	        }
	    }
	}
}
