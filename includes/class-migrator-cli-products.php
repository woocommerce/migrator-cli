<?php

class Migrator_CLI_Products {

	const SHOPIFY_PRODUCT_QUERY = <<<'GRAPHQL'
	query GetShopifyProducts(
		$first: Int!,
		$after: String,
		$query: String
	) {
		products(first: $first, after: $after, query: $query) {
			edges {
				cursor
				node {
					id
					title
					handle
					bodyHtml
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
					featuredImage {
						id
						url
						altText
					}
					images(first: 50) { # Fetch up to 50 images
						edges {
							node {
								id
								url
								altText
							}
						}
					}
					variants(first: 100) { # Fetch up to 100 variants
						edges {
							node {
								id
								product { id } # Needed for linking variation meta
								price
								compareAtPrice
								sku
								inventoryManagement
								inventoryPolicy
								inventoryQuantity
								weight
								weightUnit
								position
								image {
									id
									url
									altText
								}
								selectedOptions {
									name
									value
								}
							}
						}
					}
					collections(first: 20) { # Fetch up to 20 collections
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

	private $fields;
	private $migration_data;
	private $assoc_args;
	private $saved_filters; // Added for disable/restore hooks

	/**
	 * Main entry point for migrating products.
	 *
	 * @param array $assoc_args Command-line arguments.
	 */
	public function migrate_products( $assoc_args ) {
		Migrator_CLI_Utils::health_check();
		$this->disable_hooks(); // Disable hooks early

		$args = $this->parse_and_validate_args( $assoc_args );
		if ( ! $args ) {
			$this->restore_hooks(); // Restore hooks if args are invalid
			return; // Error handled in parse_and_validate_args
		}

		// Fetch estimated count for progress bar
		$total_count = $this->fetch_estimated_total_count( $args );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing Products', $total_count );

		$overall_start_time = microtime( true );
		$total_processed_count = 0;
		$limit_remaining = $args->limit;
		$after_cursor = $args->after_cursor;

		do {
			$batch_start_time = microtime( true );
			$batch_limit = min( $args->perpage, $limit_remaining );
			if ( $batch_limit <= 0 ) {
				break; // Limit reached
			}

			// 1. Fetch a batch of products
			$fetch_args = (
				(object) array(
					'limit' => $batch_limit,
					'after_cursor' => $after_cursor,
					'query_filter' => $args->query_filter,
				)
			);
			$response_data = $this->fetch_product_batch( $fetch_args );

			if ( ! $response_data || empty( $response_data->products ) ) {
				WP_CLI::line( 'No more products found or failed to fetch batch.' );
				break;
			}

			// 2. Process the fetched batch, passing the progress bar object
			$batch_result = $this->process_product_batch(
				$response_data->products,
				$args,
				$progress
			);

			$batch_processed_count = $batch_result['processed_count'];
			$total_processed_count += $batch_processed_count;
			$limit_remaining -= $batch_processed_count;
			$after_cursor = $batch_result['last_cursor']; // Update cursor from batch processing
			$has_next_page = $response_data->pageInfo->hasNextPage;

			WP_CLI::line( sprintf( 'Batch processed %d products in %.2f seconds.', $batch_processed_count, microtime( true ) - $batch_start_time ) );
			WP_CLI::line( ''); // Add a newline for readability

			// Clear cache if continuing
			if ( $has_next_page && $limit_remaining > 0 ) {
				Migrator_CLI_Utils::reset_in_memory_cache();
			}

		} while ( $has_next_page && $limit_remaining > 0 );

		// 3. Finalize
		$progress->finish(); // Finish the progress bar
		$this->restore_hooks(); // Restore hooks at the very end
		$this->print_summary( $total_processed_count, $overall_start_time );
	}

	/**
	 * Parses and validates command-line arguments.
	 *
	 * @param array $assoc_args Raw arguments.
	 * @return object|false Parsed arguments object or false on error.
	 */
	private function parse_and_validate_args( $assoc_args ) {
		$this->assoc_args = $assoc_args; // Store for use in should_process

		// Set fields
		if ( isset( $assoc_args['fields'] ) ) {
			$this->fields = explode( ',', $assoc_args['fields'] );
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . sprintf( 'Only migrate/update selected fields: %s', implode( ', ', $this->fields ) ) );
		} else {
			$this->fields = $this->get_product_fields();
		}
		if ( isset( $assoc_args['exclude-fields'] ) ) {
			$exclude_fields = explode( ',', $assoc_args['exclude-fields'] );
			$this->fields   = array_diff( $this->fields, $exclude_fields );
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . sprintf( 'Excluding these fields: %s', implode( ', ', $exclude_fields ) ) );
		}

		// Parse other arguments
		$args = new stdClass();
		$args->limit        = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : PHP_INT_MAX;
		$args->perpage      = isset( $assoc_args['perpage'] ) ? min( (int) $assoc_args['perpage'], 250 ) : 250;
		$args->no_update    = isset( $assoc_args['no-update'] );
		$args->exclude_ids  = isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array();
		$args->after_cursor = isset( $assoc_args['next' ] ) ? $assoc_args['next'] : null;
		$args->target_rest_ids = isset( $assoc_args['ids'] ) ? explode(',', $assoc_args['ids']) : null;

		// Build GraphQL query filter string
		$query_parts = array();
		if ( isset( $assoc_args['status'] ) ) {
			$query_parts[] = 'status:' . strtoupper( $assoc_args['status'] );
		}
		if ( isset( $assoc_args['handle'] ) ) {
			$query_parts[] = 'handle:' . $assoc_args['handle'];
		}
		if ( isset( $assoc_args['product-type'] ) && 'all' !== $assoc_args['product-type'] ) {
			$query_parts[] = 'product_type:"' . $assoc_args['product-type'] . '"';
		}
		if ( isset( $assoc_args['before'] ) ) {
			$query_parts[] = 'created_at:<=' . $assoc_args['before'];
		}
		if ( isset( $assoc_args['after'] ) ) {
			$query_parts[] = 'created_at:>=' . $assoc_args['after'];
		}
		$args->query_filter = implode( ' AND ', $query_parts );

		WP_CLI::line( 'Starting product migration using GraphQL...' );
		if ( $args->query_filter ) {
			WP_CLI::line( 'Using query filter: ' . $args->query_filter );
		}
		WP_CLI::line( '');

		return $args;
	}

	/**
	 * Fetches a batch of products from the Shopify GraphQL API.
	 *
	 * @param object $fetch_args Arguments for fetching (limit, after_cursor, query_filter).
	 * @return object|false Response data object or false on failure.
	 */
	private function fetch_product_batch( $fetch_args ) {
		WP_CLI::line( sprintf( 'Fetching next %d products%s...',
			$fetch_args->limit,
			$fetch_args->after_cursor ? ' after cursor ' . $fetch_args->after_cursor : ''
		));

		$variables = array(
			'first' => $fetch_args->limit,
			'after' => $fetch_args->after_cursor,
			'query' => $fetch_args->query_filter,
		);

		$response_data = Migrator_CLI_Utils::graphql_request( self::SHOPIFY_PRODUCT_QUERY, $variables );

		if ( is_wp_error( $response_data ) || ! isset( $response_data->products ) ) {
			WP_CLI::error( 'Failed to fetch products via GraphQL. ' . ( is_wp_error( $response_data ) ? $response_data->get_error_message() : 'Invalid response structure.' ) );
			return false;
		}

		WP_CLI::line( sprintf( 'Fetched %d products.', count( $response_data->products->edges ) ) );
		return (
			(object) array(
				'products' => $response_data->products->edges,
				'pageInfo' => $response_data->products->pageInfo,
			)
		);
	}

	/**
	 * Processes a batch of fetched Shopify products.
	 *
	 * @param array $products Array of product edges from GraphQL.
	 * @param object $args Parsed command arguments.
	 * @param \WP_CLI\Utils\ProgressBar $progress Progress bar instance.
	 * @return array Result containing processed count and last cursor.
	 */
	private function process_product_batch( $products, $args, $progress ) {
		$processed_count = 0;
		$last_cursor = null;

		foreach ( $products as $edge ) {
			$shopify_product = $edge->node;
			$last_cursor     = $edge->cursor;

			$process_result = $this->process_single_product(
				$shopify_product,
				$args,
				$progress
			);

			if ( $process_result['processed'] ) {
				$processed_count++;
			}
		}

		return array(
			'processed_count' => $processed_count,
			'last_cursor' => $last_cursor,
		);
	}

	/**
	 * Processes a single Shopify product.
	 *
	 * @param object $shopify_product Product node from GraphQL.
	 * @param object $args Parsed command arguments.
	 * @param \WP_CLI\Utils\ProgressBar $progress Progress bar instance.
	 * @return array Result indicating if processed.
	 */
	private function process_single_product( $shopify_product, $args, $progress ) {
		$rest_id = basename( $shopify_product->id );
		$processed = false;
		$ticked = false; // Track if progress was ticked for this product

		WP_CLI::line( sprintf( 'Processing product %s (Rest ID: %s)...', $shopify_product->handle, $rest_id ) );
		$product_start_time = microtime( true );

		// Handle --ids filter
		if ( isset( $args->target_rest_ids ) && ! in_array( $rest_id, $args->target_rest_ids ) ) {
			WP_CLI::line( sprintf( 'Skipping product %s (Rest ID: %s) - Not in target IDs.', $shopify_product->handle, $rest_id ) );
			$progress->tick(); // Tick even if skipped when filtering by ID
			$ticked = true;
			return array( 'processed' => false );
		}

		// Handle --exclude filter
		if ( in_array( $rest_id, $args->exclude_ids ) ) {
			WP_CLI::line( sprintf( 'Skipping product %s (Rest ID: %s) - Excluded.', $shopify_product->handle, $rest_id ) );
			// Don't tick for excludes as they aren't part of the estimated total
			return array( 'processed' => false );
		}

		// Check if product exists
		$woo_product = $this->get_corresponding_woo_product( $shopify_product );

		if ( $woo_product && $args->no_update ) {
			WP_CLI::line( sprintf( 'Skipping product %s (ID: %s) - Product already exists and --no-update flag is set.', $shopify_product->handle, $woo_product->get_id() ) );
			$progress->tick(); // Tick for existing products if not updating
			$ticked = true;
		} else {
			try {
				// Create or update the product
				$this->create_or_update_woo_product( $shopify_product, $woo_product );
				$processed = true;
				$progress->tick(); // Tick after successful processing
				$ticked = true;
			} catch ( Exception $e ) {
				WP_CLI::warning( sprintf( 'Failed processing product %s (Rest ID: %s). Error: %s', $shopify_product->handle, $rest_id, $e->getMessage() ) );
				// Don't tick if processing failed - let the loop continue and potentially retry or skip
			}
		}

		$product_duration = microtime( true ) - $product_start_time;
		WP_CLI::line( sprintf( 'Product %s (Rest ID: %s) finished in %.2f seconds.', $shopify_product->handle, $rest_id, $product_duration ) );


		return array( 'processed' => $processed );
	}

	/**
	 * Prints the final migration summary.
	 *
	 * @param int $processed_count Total products processed.
	 * @param float $overall_start_time Timestamp when the migration started.
	 */
	private function print_summary( $processed_count, $overall_start_time ) {
		$overall_end_time = microtime( true );
		$total_duration = $overall_end_time - $overall_start_time;

		WP_CLI::line( '---------------------------------' );
		WP_CLI::success( sprintf( 'Finished migrating products. Processed: %d', $processed_count ) );
		WP_CLI::line( sprintf( 'Total migration time: %.2f seconds.', $total_duration ) );

		if ( $processed_count > 0 ) {
			$average_duration = $total_duration / $processed_count;
			WP_CLI::line( sprintf( 'Average time per product: %.2f seconds.', $average_duration ) );
		} else {
			WP_CLI::line( 'No products processed to calculate average time.' );
		}
		WP_CLI::line( '---------------------------------' );
	}

	/**
	 * Disables unnecessary WordPress hooks and suspends cache invalidation.
	 */
	private function disable_hooks() {
		global $wp_filter;
		$this->saved_filters = array();
		$hooks_to_disable = array(
			'save_post',
			'wp_insert_post',
			'added_post_meta',
			'updated_post_meta',
			'deleted_post_meta',
			'woocommerce_product_object_updated_props',
			'woocommerce_new_product',
			'woocommerce_update_product',
			'woocommerce_before_product_object_save',
			'woocommerce_after_product_object_save',
		);
		foreach ( $hooks_to_disable as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$this->saved_filters[ $hook ] = $wp_filter[ $hook ];
				remove_all_actions( $hook );
			}
		}
		wp_suspend_cache_invalidation( true );
		WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Disabled hooks and suspended cache invalidation.' );
	}

	/**
	 * Restores WordPress hooks and cache invalidation.
	 */
	private function restore_hooks() {
		wp_suspend_cache_invalidation( false );
		if ( ! empty( $this->saved_filters ) ) {
			global $wp_filter;
			foreach ( $this->saved_filters as $hook => $filter ) {
				$wp_filter[ $hook ] = $filter;
			}
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Restored hooks and cache invalidation.' );
		}
	}

	private function get_product_fields() {
		return array(
			'title',
			'slug',
			'description',
			'status',
			'date_created',
			'catalog_visibility',
			'category',
			'tag',
			'price',
			'sku',
			'stock',
			'weight',
			'brand',
			'images',
			'seo',
			'attributes',
		);
	}

	private function preg_match_array( $subject, $patterns ) {
		if ( ! $subject ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			if ( strpos( $pattern, '*' ) !== false ) {
				$pattern = str_replace( '*', '.*', $pattern );
			}
			if ( preg_match( "/^$pattern$/i", $subject ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_variable_product( $shopify_product ) {
		return count( $shopify_product->variants->edges ) > 1;
	}

	private function get_corresponding_woo_product( $shopify_product ) {
		$rest_id = basename( $shopify_product->id );

		$woo_products = wc_get_products(
			array(
				'limit'      => 1,
				'meta_key'   => '_original_product_id',
				'meta_value' => $rest_id,
			)
		);

		if ( count( $woo_products ) === 1 && is_a( $woo_products[0], 'WC_Product' ) ) {
			return wc_get_product( $woo_products[0] );
		}
	}

	private function create_or_update_woo_product( $shopify_product, $woo_product = null ) {
		$rest_id = basename( $shopify_product->id );

		$this->migration_data = array(
			'product_id'         => $rest_id,
			'original_url'       => '',
			'images_mapping'     => array(),
			'metafields'         => array(),
			'variations_mapping' => array(),
		);

		if ( $woo_product ) {
			$saved_migration_data = $woo_product->get_meta( '_migration_data' );
			if ( $saved_migration_data && is_array( $saved_migration_data ) ) {
				$this->migration_data = array_merge( $this->migration_data, $saved_migration_data );
			}
		}

		if ( $this->is_variable_product( $shopify_product ) ) {
			$product = new WC_Product_Variable( $woo_product );
		} else {
			$product = new WC_Product_Simple( $woo_product );
		}

		if ( $this->should_process( 'title' ) ) {
			$product->set_name( $shopify_product->title );
		}

		if ( $this->should_process( 'slug' ) ) {
			$product->set_slug( $shopify_product->handle );
		}

		if ( $this->should_process( 'description' ) ) {
			$product->set_description( $this->sanitize_product_description( $shopify_product->bodyHtml ) );
		}

		if ( $this->should_process( 'status' ) ) {
			$product->set_status( $this->get_woo_product_status( $shopify_product ) );
		}

		if ( $this->should_process( 'date_created' ) ) {
			$product->set_date_created( $shopify_product->createdAt );
		}

		if ( $this->should_process( 'catalog_visibility' ) ) {
			if ( property_exists( $shopify_product, 'onlineStoreUrl' ) ) {
				if ( null === $shopify_product->onlineStoreUrl ) {
					$product->set_catalog_visibility( 'hidden' );
				} else {
					$this->migration_data['original_url'] = $shopify_product->onlineStoreUrl;
				}
			}
		}

		if ( $this->should_process( 'category' ) ) {
			$product->set_category_ids( $this->get_woo_product_category_ids( $shopify_product ) );
		}

		if ( $this->should_process( 'tag' ) ) {
			$product->set_tag_ids( $this->get_woo_product_tag_ids( $shopify_product ) );
		}

		if ( ! $this->is_variable_product( $shopify_product ) && ! empty( $shopify_product->variants->edges ) ) {
			$variant_node = $shopify_product->variants->edges[0]->node;
			if ( $this->should_process( 'price' ) ) {
				if ( $variant_node->compareAtPrice && $variant_node->compareAtPrice > $variant_node->price ) {
					$product->set_sale_price( $variant_node->price );
					$product->set_regular_price( $variant_node->compareAtPrice );
				} else {
					$product->set_sale_price( '' );
					$product->set_regular_price( $variant_node->price );
				}
			}
			if ( $this->should_process( 'sku' ) ) {
				add_filter( 'wc_product_has_unique_sku', '__return_false', 10, 3 );
				$product->set_sku( $variant_node->sku );
				remove_filter( 'wc_product_has_unique_sku', '__return_false' );
			}
			if ( $this->should_process( 'stock' ) ) {
				$product->set_manage_stock( 'SHOPIFY' === $variant_node->inventoryManagement );
				$product->set_stock_status( $variant_node->inventoryQuantity <= 0 && 'DENY' === $variant_node->inventoryPolicy ? 'outofstock' : 'instock' );
				$product->set_stock_quantity( $variant_node->inventoryQuantity );
			}
			if ( $this->should_process( 'weight' ) ) {
				$product->set_weight( $this->get_converted_weight( $variant_node->weight, $variant_node->weightUnit ) );
			}
			$variant_rest_id = basename( $variant_node->id );
			$product->update_meta_data( '_original_variant_id', $variant_rest_id );
		} else {
			$product->set_sku( '' );
		}

		$product->save();

		if ( $this->should_process( 'brand' ) ) {
			$this->set_woo_product_brand( $shopify_product, $product );
		}

		if ( $this->should_process( 'images' ) ) {
			$this->upload_images( $shopify_product, $product );
			$product->set_image_id( $this->get_woo_product_image_id( $shopify_product ) );
			$product->set_gallery_image_ids( $this->get_woo_product_gallery_image_ids( $shopify_product ) );
		}

		$product->save();

		if ( $this->is_variable_product( $shopify_product ) ) {
			$this->create_or_update_woo_product_variations( $shopify_product, $product );
		}

		if ( $this->should_process( 'seo' ) ) {
			$this->update_seo_title_description( $shopify_product, $product );
		}

		if ( property_exists( $shopify_product, 'metafields' ) && ! empty( $shopify_product->metafields->edges ) ) {
			foreach ( $shopify_product->metafields->edges as $edge ) {
				$field_node = $edge->node;
				$key = sprintf( '%s_%s', $field_node->namespace, $field_node->key );
				$this->migration_data['metafields'][ $key ] = $field_node->value;
			}
		}

		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->update_meta_data( '_original_product_id', $rest_id );

		$product->save();
		WP_CLI::line( 'Woo Product ID: ' . $product->get_id() );
	}

	private function should_process( $field ) {
		return in_array( $field, $this->fields, true );
	}

	private function sanitize_product_description( $html ) {
		$html = htmlspecialchars_decode(htmlspecialchars($html, ENT_QUOTES, 'UTF-8', false), ENT_QUOTES);
		if ( ! $html ) return '';
		$html = preg_replace( '~<script(.*?)</script>~Usi', '', $html );
		$html = preg_replace( '~<style(.*?)</style>~Usi', '', $html );
		$html = wp_kses_post( $html );
		return trim( $html );
	}

	private function get_woo_product_status( $shopify_product ) {
		$woo_product_status = 'draft';
		if ( 'ACTIVE' === $shopify_product->status ) {
			$woo_product_status = 'publish';
		}
		return $woo_product_status;
	}

	private function get_woo_product_category_ids( $shopify_product ) {
		$category_ids = array();
		if ( ! property_exists( $shopify_product, 'collections' ) || empty( $shopify_product->collections->edges ) ) {
			$category_ids[] = get_option( 'default_product_cat' );
			return $category_ids;
		}
		$collections  = $shopify_product->collections->edges;
		foreach ( $collections as $collection_edge ) {
			$collection_node = $collection_edge->node;
			$woo_product_category = get_term_by( 'slug', $collection_node->handle, 'product_cat', ARRAY_A );
			if ( ! $woo_product_category ) {
				$woo_product_category = wp_insert_term(
					$collection_node->title,
					'product_cat',
					array( 'slug' => $collection_node->handle )
				);
				if ( is_wp_error( $woo_product_category ) ) continue;
			}
			$category_ids[] = $woo_product_category['term_id'];
		}
		if ( empty( $category_ids ) ) {
			$category_ids[] = get_option( 'default_product_cat' );
		}
		return $category_ids;
	}

	private function get_woo_product_tag_ids( $shopify_product ) {
		$tag_ids = array();
		if ( empty( $shopify_product->tags ) ) return $tag_ids;
		$tags = $shopify_product->tags;
		foreach ( $tags as $tag ) {
			$trimmed_tag = trim( $tag );
			if ( empty( $trimmed_tag ) ) continue;
			$woo_product_tag = get_term_by( 'name', $trimmed_tag, 'product_tag', ARRAY_A );
			if ( ! $woo_product_tag ) {
				$tag_slug = sanitize_title( $trimmed_tag );
				$woo_product_tag = wp_insert_term(
					$trimmed_tag,
					'product_tag',
					array( 'slug' => $tag_slug )
				);
				if ( is_wp_error( $woo_product_tag ) ) continue;
			}
			$tag_ids[] = $woo_product_tag['term_id'];
		}
		return $tag_ids;
	}

	private function get_converted_weight( $weight, $weight_unit ) {
		if ( null === $weight || null === $weight_unit ) return 0.0;
		$unit_map = array(
			'GRAMS' => 'g', 'KILOGRAMS' => 'kg', 'POUNDS' => 'lb', 'OUNCES' => 'oz',
		);
		$shopify_unit_key = isset( $unit_map[ $weight_unit ] ) ? $unit_map[ $weight_unit ] : null;
		if ( ! $shopify_unit_key ) return $weight;
		$store_weight_unit = get_option( 'woocommerce_weight_unit' );
		if ( 'lbs' === $store_weight_unit ) $store_weight_unit = 'lb';
		$conversion = array(
			'kg' => array( 'kg' => 1, 'g' => 1000, 'lb' => 2.20462, 'oz' => 35.274 ),
			'g'  => array( 'kg' => 0.001, 'g' => 1, 'lb' => 0.00220462, 'oz' => 0.035274 ),
			'lb' => array( 'kg' => 0.453592, 'g' => 453.592, 'lb' => 1, 'oz' => 16 ),
			'oz' => array( 'kg' => 0.0283495, 'g' => 28.3495, 'lb' => 0.0625, 'oz' => 1 ),
		);
		if ( ! isset( $conversion[ $shopify_unit_key ][ $store_weight_unit ] ) ) return $weight;
		return (float) $weight * $conversion[ $shopify_unit_key ][ $store_weight_unit ];
	}

	private function set_woo_product_brand( $shopify_product, $product ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) return;
		$brand = $shopify_product->vendor;
		if ( ! $brand ) return;
		$woo_product_brand = get_term_by( 'name', $brand, 'product_brand', ARRAY_A );
		if ( ! $woo_product_brand ) {
			$woo_product_brand = wp_insert_term( $brand, 'product_brand' );
			if ( is_wp_error( $woo_product_brand ) ) return;
		}
		wp_set_object_terms( $product->get_id(), $woo_product_brand['term_id'], 'product_brand' );
	}

	private function upload_images( $shopify_product, $product ) {
		if ( ! property_exists( $shopify_product, 'images' ) || empty( $shopify_product->images->edges ) ) return;
		WP_CLI::line( 'Starting image processing...' );
		if ( empty( $this->migration_data['images_mapping'] ) ) $this->migration_data['images_mapping'] = array();
		foreach ( $shopify_product->images->edges as $image_edge ) {
			$image_node = $image_edge->node;
			$image_gql_id = $image_node->id;
			if ( isset( $this->migration_data['images_mapping'][ $image_gql_id ] ) && wp_attachment_is_image( $this->migration_data['images_mapping'][ $image_gql_id ] ) ) continue;
			$memory_before = round( memory_get_usage() / 1024 / 1024, 2 );
			$memory_limit = ini_get('memory_limit');
			WP_CLI::line( sprintf( '- Uploading image %s from %s... (Memory Usage: %s MB / Limit: %s)', $image_gql_id, $image_node->url, $memory_before, $memory_limit ) );
			$upload_start_time = microtime(true);
			$image_id = media_sideload_image( $image_node->url, $product->get_id(), $image_node->altText, 'id' );
			$upload_duration = microtime(true) - $upload_start_time;
			$memory_after = round( memory_get_usage() / 1024 / 1024, 2 );
			if ( is_wp_error( $image_id ) ) {
				WP_CLI::warning( sprintf( ' - Error uploading %s: %s (Duration: %.2f seconds, Memory after: %s MB)', $image_node->url, $image_id->get_error_message(), $upload_duration, $memory_after ) );
				continue;
			}
			$this->migration_data['images_mapping'][ $image_gql_id ] = $image_id;
			WP_CLI::line( sprintf( ' - Mapped image %s to attachment ID %s. (Upload took %.2f seconds, Memory after: %s MB)', $image_gql_id, $image_id, $upload_duration, $memory_after ) );
		}
		$product->update_meta_data( '_migration_data', $this->migration_data );
	}

	private function get_woo_product_image_id( $shopify_product ) {
		if ( empty( $shopify_product->featuredImage ) || empty( $this->migration_data['images_mapping'] ) ) return 0;
		$featured_image_gql_id = $shopify_product->featuredImage->id;
		return isset( $this->migration_data['images_mapping'][ $featured_image_gql_id ] ) ? $this->migration_data['images_mapping'][ $featured_image_gql_id ] : 0;
	}

	private function get_woo_product_gallery_image_ids( $shopify_product ) {
		$gallery_ids = array();
		$featured_image_wp_id = $this->get_woo_product_image_id( $shopify_product );
		if ( empty( $this->migration_data['images_mapping'] ) ) return $gallery_ids;
		$all_wp_image_ids = array_values( $this->migration_data['images_mapping'] );
		if ( $featured_image_wp_id ) {
			$gallery_ids = array_diff( $all_wp_image_ids, array( $featured_image_wp_id ) );
		} else {
			$gallery_ids = $all_wp_image_ids;
		}
		return array_values( $gallery_ids );
	}

	private function create_or_update_woo_product_variations( $shopify_product, $product ) {
		$attribute_taxonomy_mapping = array();
		$woo_attributes = array();
		if ( $this->should_process( 'attributes' ) && property_exists( $shopify_product, 'options' ) && ! empty( $shopify_product->options ) ) {
			foreach ( $shopify_product->options as $option ) {
				$taxonomy_slug = sanitize_title( $option->name );
				$taxonomy_name = 'pa_' . $taxonomy_slug;
				if ( ! taxonomy_exists( $taxonomy_name ) ) {
					$attribute_id = wc_create_attribute( array( 'name' => $option->name, 'slug' => $taxonomy_slug, 'type' => 'select', 'order_by' => 'menu_order' ) );
					if ( is_wp_error( $attribute_id ) ) continue;
				} else {
					$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy_name );
				}
				$attribute_taxonomy_mapping[ $option->name ] = $taxonomy_name;
				$term_ids = array();
				foreach ( $option->values as $value ) {
					$term_slug = sanitize_title( $value );
					$term = get_term_by( 'slug', $term_slug, $taxonomy_name, ARRAY_A );
					if ( ! $term ) {
						$term_result = wp_insert_term( $value, $taxonomy_name, array( 'slug' => $term_slug ) );
						if ( is_wp_error( $term_result ) ) continue;
						$term_ids[] = $term_result['term_id'];
					} else {
						$term_ids[] = $term['term_id'];
					}
				}
				$woo_attribute = new WC_Product_Attribute();
				$woo_attribute->set_name( $taxonomy_name );
				$woo_attribute->set_id( $attribute_id );
				$woo_attribute->set_options( $term_ids );
				$woo_attribute->set_position( $option->position );
				$woo_attribute->set_visible( true );
				$woo_attribute->set_variation( true );
				$woo_attributes[] = $woo_attribute;
			}
			unregister_taxonomy( 'product_type' );
			WC_Post_Types::register_taxonomies();
			$product->set_attributes( $woo_attributes );
			$product->save();
		}

		if ( ! property_exists( $shopify_product, 'variants' ) || empty( $shopify_product->variants->edges ) ) return;

		if ( empty( $this->migration_data['variations_mapping'] ) ) $this->migration_data['variations_mapping'] = array();
		$processed_variation_ids = array();

		foreach ( $shopify_product->variants->edges as $variant_edge ) {
			$variant_node = $variant_edge->node;
			$variant_gql_id = $variant_node->id;
			$variant_rest_id = basename( $variant_gql_id );
			$variation = null;

			if ( isset( $this->migration_data['variations_mapping'][ $variant_gql_id ] ) ) {
				$_variation = wc_get_product( $this->migration_data['variations_mapping'][ $variant_gql_id ] );
				if ( is_a( $_variation, 'WC_Product_Variation' ) ) $variation = $_variation;
			} else {
				$found_variations = get_posts( array( 'post_parent' => $product->get_id(), 'post_type' => 'product_variation', 'numberposts' => 1, 'meta_key' => '_original_variant_id', 'meta_value' => $variant_rest_id ) );
				if ( ! empty( $found_variations ) ) {
					$variation = new WC_Product_Variation( $found_variations[0]->ID );
					$this->migration_data['variations_mapping'][ $variant_gql_id ] = $variation->get_id();
				}
			}

			if ( ! $variation ) $variation = new WC_Product_Variation();

			$variation->set_parent_id( $product->get_id() );
			$variation->set_menu_order( $variant_node->position );
			$variation->set_status( 'publish' );

			if ( $this->should_process( 'stock' ) ) {
				$variation->set_manage_stock( 'SHOPIFY' === $variant_node->inventoryManagement );
				$variation->set_stock_quantity( $variant_node->inventoryQuantity );
				$variation->set_stock_status( $variant_node->inventoryQuantity <= 0 && 'DENY' === $variant_node->inventoryPolicy ? 'outofstock' : 'instock' );
			}

			if ( $this->should_process( 'weight' ) ) {
				$variation->set_weight( $this->get_converted_weight( $variant_node->weight, $variant_node->weightUnit ) );
			}

			if ( $this->should_process( 'images' ) && $variant_node->image ) {
				$variant_image_gql_id = $variant_node->image->id;
				if ( isset( $this->migration_data['images_mapping'][ $variant_image_gql_id ] ) ) {
					$variation->set_image_id( $this->migration_data['images_mapping'][ $variant_image_gql_id ] );
				} else {
					WP_CLI::line( sprintf( 'Variant image %s not found in mapping. Attempting direct upload...', $variant_image_gql_id ) );
					$image_id = media_sideload_image( $variant_node->image->url, $product->get_id(), $variant_node->image->altText, 'id' );
					if ( ! is_wp_error( $image_id ) ) {
						$variation->set_image_id( $image_id );
						$this->migration_data['images_mapping'][ $variant_image_gql_id ] = $image_id;
					} else {
						WP_CLI::warning( sprintf( 'Failed to upload variant image %s: %s', $variant_node->image->url, $image_id->get_error_message() ) );
					}
				}
			}

			if ( $this->should_process( 'price' ) ) {
				if ( $variant_node->compareAtPrice && $variant_node->compareAtPrice > $variant_node->price ) {
					$variation->set_regular_price( $variant_node->compareAtPrice );
					$variation->set_sale_price( $variant_node->price );
				} else {
					$variation->set_regular_price( $variant_node->price );
					$variation->set_sale_price( '' );
				}
			}

			if ( $this->should_process( 'sku' ) ) {
				if ( $variant_node->sku ) {
					add_filter( 'wc_product_has_unique_sku', '__return_false', 10, 3 );
					$variation->set_sku( $variant_node->sku );
					remove_filter( 'wc_product_has_unique_sku', '__return_false' );
				}
			}

			if ( $this->should_process( 'attributes' ) && ! empty( $variant_node->selectedOptions ) ) {
				$variation_attributes = array();
				foreach ( $variant_node->selectedOptions as $selectedOption ) {
					if ( isset( $attribute_taxonomy_mapping[ $selectedOption->name ] ) ) {
						$taxonomy = $attribute_taxonomy_mapping[ $selectedOption->name ];
						$term = get_term_by( 'name', $selectedOption->value, $taxonomy );
						if ( $term ) {
							$variation_attributes[ $taxonomy ] = $term->slug;
						} else {
							WP_CLI::warning( "Could not find term '{$selectedOption->value}' in taxonomy '{$taxonomy}' for variation {$variant_gql_id}." );
						}
					} else {
						WP_CLI::warning("Attribute taxonomy mapping not found for option '{$selectedOption->name}'.");
					}
				}
				$variation->set_attributes( $variation_attributes );
			}

			$variation->update_meta_data( '_original_variant_id', $variant_rest_id );
			$variation->update_meta_data( '_original_product_id', basename( $variant_node->product->id ) );
			$variation_id = $variation->save();
			$processed_variation_ids[] = $variation_id;
			$this->migration_data['variations_mapping'][ $variant_gql_id ] = $variation_id;
		}

		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->save();
		$this->clean_up_orphan_variations( $product, $processed_variation_ids );
	}

	private function update_seo_title_description( $shopify_product, WC_Product $product ) {
		if ( ! defined( 'WPSEO_VERSION' ) ) return;
		$current_seo_title = $product->get_meta( '_yoast_wpseo_title' );
		$current_seo_description = $product->get_meta( '_yoast_wpseo_metadesc' );
		$title = $product->get_name();
		$description = $product->get_description() ? $product->get_description() : wp_strip_all_tags( get_the_excerpt( $product->get_id() ) );
		if ( property_exists( $shopify_product, 'metafields' ) && ! empty( $shopify_product->metafields->edges ) ) {
			foreach ( $shopify_product->metafields->edges as $edge ) {
				$field_node = $edge->node;
				if ( 'global' === $field_node->namespace && 'title_tag' === $field_node->key && ! empty( $field_node->value ) ) $title = $field_node->value;
				if ( 'global' === $field_node->namespace && 'description_tag' === $field_node->key && ! empty( $field_node->value ) ) $description = $field_node->value;
			}
		}
		if ( $current_seo_title !== $title ) $product->update_meta_data( '_yoast_wpseo_title', $title );
		if ( $current_seo_description !== $description ) $product->update_meta_data( '_yoast_wpseo_metadesc', $description );
	}

	private function clean_up_orphan_variations( $product, $processed_variation_ids ) {
		if ( ! isset( $this->assoc_args['remove-orphans'] ) ) return;
		$existing_variations = $product->get_children();
		$orphans = array_diff( $existing_variations, $processed_variation_ids );
		if ( ! empty( $orphans ) ) {
			WP_CLI::line( 'Removing ' . count( $orphans ) . ' orphan variations...' );
			foreach ( $orphans as $orphan_id ) {
				$variation_obj = wc_get_product( $orphan_id );
				if ( $variation_obj ) {
					$variation_obj->delete( true );
					WP_CLI::line( 'Removed orphan variation ID: ' . $orphan_id );
				}
			}
		}
	}

	/**
	 * Fetches an estimated total product count from Shopify REST API.
	 *
	 * Note: This is an estimate as the count endpoint doesn't support all filters (e.g., handle, product_type).
	 * If --limit or --ids are provided, those are used instead for a more accurate progress bar.
	 *
	 * @param object $args Parsed command arguments.
	 * @return int|null Estimated total count, or null if count couldn't be determined.
	 */
	private function fetch_estimated_total_count( $args ) {
		$count_params = array();
		// Extract supported filters from the query_filter string (or pass original args)
		// Example: assuming $args object holds original values if needed
		if ( isset( $this->assoc_args['status'] ) ) {
			$count_params['status'] = $this->assoc_args['status']; // REST uses lowercase
		}

		WP_CLI::line( 'Fetching estimated total product count from REST API...' );
		$response = Migrator_CLI_Utils::rest_request( 'products/count.json', $count_params );

		if ( $response && isset( $response->data->count ) ) {
			$count = (int) $response->data->count;
			WP_CLI::line( sprintf( 'Estimated total products matching filters (status, created_at): %d', $count ) );

			return $count;
		} else {
			WP_CLI::warning( 'Could not fetch estimated total product count. Progress bar may not show percentage.' );
			return null; // Indicate unknown count
		}
	}
}
