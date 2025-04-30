<?php

class Migrator_CLI_Products {

	const SHOPIFY_PRODUCT_QUERY = <<<'GRAPHQL'
	query GetShopifyProducts(
		$first: Int!,
		$after: String,
		$query: String,
		$variantsFirst: Int = 250 # Add variable for variant count
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

	private $fields;
	private $migration_data;
	private $assoc_args;
	private $saved_filters; // Added for disable/restore hooks

	private $verbose;

	/**
	 * Main entry point for migrating products.
	 *
	 * @param array $assoc_args Command-line arguments ['before'] ['after'] ['limit'] ['perpage'] ['next'] ['status'] ['ids'] ['exclude'] ['handle'] ['product-type'] ['skip-update'] ['verbose']
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
		$total_count = $this->fetch_total_product_count( $assoc_args );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing Products', $total_count );

		$overall_start_time    = microtime( true );
		$total_processed_count = 0;
		$limit_remaining       = $args->limit;
		$after_cursor          = $args->after_cursor;

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
					'variants_per_product' => $args->variants_per_product,
				)
			);
			$response_data = $this->fetch_product_batch( $fetch_args, $total_count );

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

			// only print if verbose is on
			if ( $this->verbose ) {
				WP_CLI::line( sprintf( 'Batch processed %d products in %.2f seconds.', $batch_processed_count, microtime( true ) - $batch_start_time ) );
				WP_CLI::line( '' ); // Add a newline for readability
			}

			// Clear cache if continuing
			if ( $has_next_page && $limit_remaining > 0 ) {
				Migrator_CLI_Utils::reset_in_memory_cache();
			}

		} while ( $has_next_page && $limit_remaining > 0 );

		// 3. Finalize
		$progress->finish(); // Finish the progress bar
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

		// Parse other arguments.
		$args = new stdClass();
		$args->limit              = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : PHP_INT_MAX;
		$args->perpage            = isset( $assoc_args['perpage'] ) ? min( (int) $assoc_args['perpage'], 250 ) : 100;
		$args->skip_update        = isset( $assoc_args['skip-update'] );
		$args->exclude_ids        = isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array();
		$args->after_cursor    	  = isset( $assoc_args['next'] ) ? $assoc_args['next'] : null;
		$args->target_product_ids = isset( $assoc_args['ids'] ) ? explode( ',', $assoc_args['ids'] ) : null;
		$this->verbose            = isset( $assoc_args['verbose'] );

		// Parse variants per product option
		$variants_per_product_default = 250;
		$args->variants_per_product = isset( $assoc_args['variants-per-product'] ) ? (int) $assoc_args['variants-per-product'] : $variants_per_product_default;
		if ( $args->variants_per_product < 1 || $args->variants_per_product > 2000 ) {
			WP_CLI::warning( 'Invalid value for --variants-per-product. Must be between 1 and 2000. Using default: ' . $variants_per_product_default );
			$args->variants_per_product = $variants_per_product_default;
		} elseif ( $args->variants_per_product !== $variants_per_product_default ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Fetching ' . $args->variants_per_product . ' variants per product.' );
		}

		// Build GraphQL query filter string.
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
		WP_CLI::line( '' );

		return $args;
	}

	/**
	 * Fetches a batch of products from the Shopify GraphQL API.
	 *
	 * @param object $fetch_args Arguments for fetching (limit, after_cursor, query_filter).
	 * @param int $total_count The total number of products to fetch.
	 * @return object|false Response data object or false on failure.
	 */
	private function fetch_product_batch( $fetch_args, $total_count ) {
		WP_CLI::line( sprintf( 'Fetching next %d products...', min( $fetch_args->limit, $total_count ) ) );

		$variables = array(
			'first' => $fetch_args->limit,
			'after' => $fetch_args->after_cursor,
			'query' => $fetch_args->query_filter,
			'variantsFirst' => $fetch_args->variants_per_product,
		);

		$response_data = Migrator_CLI_Utils::graphql_request( self::SHOPIFY_PRODUCT_QUERY, $variables );

		if ( is_wp_error( $response_data ) || ! isset( $response_data->products ) ) {
			WP_CLI::error( 'Failed to fetch products via GraphQL. ' . ( is_wp_error( $response_data ) ? $response_data->get_error_message() : 'Invalid response structure.' ) );
			return false;
		}

		WP_CLI::line( sprintf( 'Successfully fetched %d products.', count( $response_data->products->edges ) ) );
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
			'last_cursor'     => $last_cursor,
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
		$shopify_product_id = basename( $shopify_product->id );
		$processed          = false;
		$ticked             = false; // Track if progress was ticked for this product

		if ( $this->verbose ) {
			WP_CLI::line( sprintf( 'Processing product %s (Shopify Product ID: %s)...', $shopify_product->handle, $shopify_product_id ) );
		}
		$product_start_time = microtime( true );

		// Handle --ids filter
		if ( isset( $args->target_product_ids ) && ! in_array( $shopify_product_id, $args->target_product_ids, true ) ) {
			if ( $this->verbose ) {
				WP_CLI::line( sprintf( 'Skipping product %s (Shopify Product ID: %s) - Not in target IDs.', $shopify_product->handle, $shopify_product_id ) );
			}
			$progress->tick(); // Tick even if skipped when filtering by ID
			$ticked = true;
			return array( 'processed' => false );
		}

		// Handle --exclude filter
		if ( in_array( $shopify_product_id, $args->exclude_ids, true ) ) {
			if ( $this->verbose ) {
				WP_CLI::line( sprintf( 'Skipping product %s (Shopify Product ID: %s) - Excluded.', $shopify_product->handle, $shopify_product_id ) );
			}
			// Don't tick for excludes as they aren't part of the estimated total
			return array( 'processed' => false );
		}

		// Check if product exists
		$woo_product = $this->get_corresponding_woo_product( $shopify_product );

		if ( $woo_product && $args->skip_update ) {
			if ( $this->verbose ) {
				WP_CLI::line( sprintf( 'Skipping product %s (ID: %s) - Product already exists and --skip-update flag is set.', $shopify_product->handle, $woo_product->get_id() ) );
			}
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
				WP_CLI::warning( sprintf( 'Failed processing product %s (Shopify Product ID: %s). Error: %s', $shopify_product->handle, $shopify_product_id, $e->getMessage() ) );
				// Don't tick if processing failed - let the loop continue and potentially retry or skip
			}
		}

		$product_duration = microtime( true ) - $product_start_time;
		if ( $this->verbose ) {
			WP_CLI::line( sprintf( 'Product %s (Shopify Product ID: %s) finished in %.2f seconds.', $shopify_product->handle, $shopify_product_id, $product_duration ) );
		}

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

	/**
	 * Gets the product fields to process.
	 *
	 * @return array the product fields to process.
	 */
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

	/**
	 * Supports matching against an array of regular expressions, and will do a glob match so things like CANAL_* will match every product that starts with CANAL_.
	 *
	 * @param string $subject the subject to check.
	 * @param array $patterns the patterns to check against.
	 * @return bool true if the subject matches any of the patterns, false otherwise.
	 */
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

	/**
	 * Checks if a product is a variable product.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return bool true if the product is a variable product, false otherwise.
	 */
	private function is_variable_product( $shopify_product ) {
		return count( $shopify_product->variants->edges ) > 1;
	}

	/**
	 * Gets the Woo product that matches the Shopify product id.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return WC_Product|null the Woo product or null if not found.
	 */
	private function get_corresponding_woo_product( $shopify_product ) {
		$shopify_product_id = basename( $shopify_product->id );

		$woo_products = wc_get_products(
			array(
				'limit'      => 1,
				'meta_key'   => '_original_product_id',
				'meta_value' => $shopify_product_id,
			)
		);

		if ( count( $woo_products ) === 1 && is_a( $woo_products[0], 'WC_Product' ) ) {
			return wc_get_product( $woo_products[0] );
		}
		return null; // Explicitly return null if not found
	}

	/**
	 * Creates or updates a Woo product.
	 *
	 * @param object     $shopify_product the Shopify product data.
	 * @param ?WC_Product $woo_product    the Woo product or null if creating new.
	 */
	private function create_or_update_woo_product( $shopify_product, $woo_product = null ) {
		$shopify_product_id = basename( $shopify_product->id );

		$this->migration_data = array(
			'product_id'         => $shopify_product_id,
			'original_url'       => '',
			'images_mapping'     => array(), // Will map MediaImage GQL ID -> WP Attachment ID
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
			$product->set_description( $this->sanitize_product_description( $shopify_product->descriptionHtml ?? '' ) ); // Use descriptionHtml
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
				// Use inventoryItem->tracked to determine if stock is managed
				$manage_stock = property_exists( $variant_node, 'inventoryItem' ) && $variant_node->inventoryItem->tracked;
				$product->set_manage_stock( $manage_stock );

				// Set stock status based on quantity and inventory policy (if tracked)
				$stock_quantity = $variant_node->inventoryQuantity ?? 0;
				$allow_oversell = $manage_stock && 'CONTINUE' === $variant_node->inventoryPolicy;
				if ( $stock_quantity > 0 || $allow_oversell ) {
					$product->set_stock_status( 'instock' );
				} else {
					$product->set_stock_status( 'outofstock' );
				}
				$product->set_stock_quantity( $stock_quantity );
			}
			if ( $this->should_process( 'weight' ) ) {
				// Get weight and unit from inventoryItem -> measurement -> weight object
				$weight_data = null;
				if ( property_exists( $variant_node, 'inventoryItem' ) && is_object( $variant_node->inventoryItem ) &&
					 property_exists( $variant_node->inventoryItem, 'measurement' ) && is_object( $variant_node->inventoryItem->measurement ) &&
					 property_exists( $variant_node->inventoryItem->measurement, 'weight' ) && is_object( $variant_node->inventoryItem->measurement->weight )
				) {
					$weight_data = $variant_node->inventoryItem->measurement->weight;
				}
				$weight = $weight_data ? $weight_data->value : null;
				$weight_unit = $weight_data ? $weight_data->unit : null;
				$product->set_weight( $this->get_converted_weight( $weight, $weight_unit ) );
			}
			$variant_id = basename( $variant_node->id );
			$product->update_meta_data( '_original_variant_id', $variant_id );
		} else {
			$product->set_sku( '' );
		}

		// The operations below require product id, so we need to save the product first.
		$product->save();

		if ( $this->should_process( 'brand' ) ) {
			$this->set_woo_product_brand( $shopify_product, $product );
		}

		if ( $this->should_process( 'images' ) ) {
			$this->upload_images( $shopify_product, $product ); // Must run before setting image/gallery IDs
			$product->set_image_id( $this->get_woo_product_image_id( $shopify_product ) );
			$product->set_gallery_image_ids( $this->get_woo_product_gallery_image_ids( $shopify_product ) );
		}

		$product->save(); // Save again after image/gallery IDs are set

		if ( $this->is_variable_product( $shopify_product ) ) {
			$this->create_or_update_woo_product_variations( $shopify_product, $product );
		}

		if ( $this->should_process( 'seo' ) ) {
			$this->update_seo_title_description( $shopify_product, $product );
		}

		// Migrate metafields.
		if ( property_exists( $shopify_product, 'metafields' ) && ! empty( $shopify_product->metafields->edges ) ) {
			foreach ( $shopify_product->metafields->edges as $edge ) {
				$field_node = $edge->node;
				$key = sprintf( '%s_%s', $field_node->namespace, $field_node->key );
				$this->migration_data['metafields'][ $key ] = $field_node->value;
			}
		}

		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->update_meta_data( '_original_product_id', $shopify_product_id );

		$product->save();
		if ( $this->verbose ) {
			WP_CLI::line( 'Woo Product ID: ' . $product->get_id() );
		}
	}

	/**
	 * Checks if the field is contained in the $this->fields array.
	 *
	 * @param string $field the field name.
	 * @return bool true if the field should be processed, false otherwise.
	 */
	private function should_process( $field ) {
		return in_array( $field, $this->fields, true );
	}

	/**
	 * Sanitizes the product description html.
	 *
	 * @param string $html the product description html.
	 * @return string the sanitized description.
	 */
	private function sanitize_product_description( $html ) {
		$html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		if ( ! $html ) return '';
		$html = preg_replace( '~<script(.*?)>(.*?)</script>~Usi', '', $html );
		$html = preg_replace( '~<style(.*?)>(.*?)</style>~Usi', '', $html );
		$html = wp_kses_post( $html );
		return trim( $html );
	}

	/**
	 * Converts the Shopify product status into Woo product status.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return string the Woo product status.
	 */
	private function get_woo_product_status( $shopify_product ) {
		$woo_product_status = 'draft';
		if ( 'ACTIVE' === $shopify_product->status ) {
			$woo_product_status = 'publish';
		}
		return $woo_product_status;
	}

	/**
	 * Gets the Woo product category ids that match the collection handle in
	 * $shopify_product->collections->edges[collection]->node->handle
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array the Woo product category IDs.
	 */
	private function get_woo_product_category_ids( $shopify_product ) {
		$category_ids = array();
		if ( ! property_exists( $shopify_product, 'collections' ) || empty( $shopify_product->collections->edges ) ) {
			$default_cat = get_option( 'default_product_cat' );
			if ( $default_cat ) {
				$category_ids[] = $default_cat;
			}
			return $category_ids;
		}

		foreach ( $shopify_product->collections->edges as $collection_edge ) {
			$collection_node      = $collection_edge->node;
			$woo_product_category = get_term_by( 'slug', $collection_node->handle, 'product_cat' );

			if ( ! $woo_product_category ) {
				$term_result = wp_insert_term(
					$collection_node->title,
					'product_cat',
					array( 'slug' => $collection_node->handle )
				);

				if ( is_wp_error( $term_result ) ) {
					WP_CLI::warning( "Failed to insert category '{$collection_node->title}' (slug: {$collection_node->handle}): " . $term_result->get_error_message() );
					continue;
				}
				$category_ids[] = $term_result['term_id'];
			} else {
				$category_ids[] = $woo_product_category->term_id;
			}
		}

		// Ensure no duplicates if a product is in multiple collections mapping to the same category
		$category_ids = array_unique( $category_ids );

		if ( empty( $category_ids ) ) {
			$default_cat = get_option( 'default_product_cat' );
			if ( $default_cat ) {
				$category_ids[] = $default_cat;
			}
		}

		return $category_ids;
	}

	/**
	 * Gets the Woo product tags ids that match the Shopify product tags.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array the Woo product tag IDs.
	 */
	private function get_woo_product_tag_ids( $shopify_product ) {
		$tag_ids = array();
		if ( empty( $shopify_product->tags ) ) {
			return $tag_ids;
		}

		foreach ( $shopify_product->tags as $tag ) {
			$trimmed_tag = trim( $tag );
			if ( empty( $trimmed_tag ) ) {
				continue;
			}

			$woo_product_tag = get_term_by( 'name', $trimmed_tag, 'product_tag' );
			if ( ! $woo_product_tag ) {
				$tag_slug    = sanitize_title( $trimmed_tag );
				$term_result = wp_insert_term(
					$trimmed_tag,
					'product_tag',
					array( 'slug' => $tag_slug )
				);

				if ( is_wp_error( $term_result ) ) {
					WP_CLI::warning( "Failed to insert tag '{$trimmed_tag}' (slug: {$tag_slug}): " . $term_result->get_error_message() );
					continue;
				}
				$tag_ids[] = $term_result['term_id'];
			} else {
				$tag_ids[] = $woo_product_tag->term_id;
			}
		}

		return array_unique( $tag_ids );
	}

	/**
	 * Converts weight based on Shopify weight unit to store's weight unit.
	 *
	 * @param ?float  $weight      The weight value from Shopify.
	 * @param ?string $weight_unit The weight unit from Shopify (e.g., GRAMS, KILOGRAMS).
	 * @return float The converted weight, or 0.0 if input is invalid.
	 */
	private function get_converted_weight( $weight, $weight_unit ) {
		if ( null === $weight || null === $weight_unit ) {
			return 0.0;
		}

		$unit_map = array(
			'GRAMS'     => 'g',
			'KILOGRAMS' => 'kg',
			'POUNDS'    => 'lb',
			'OUNCES'    => 'oz',
		);

		$shopify_unit_key = $unit_map[ $weight_unit ] ?? null;

		if ( ! $shopify_unit_key ) {
			WP_CLI::warning( "Unknown Shopify weight unit '{$weight_unit}'. Returning original weight {$weight}." );
			return (float) $weight;
		}

		$store_weight_unit = get_option( 'woocommerce_weight_unit' );

		if ( 'lbs' === $store_weight_unit ) {
			$store_weight_unit = 'lb';
		}

		if ( $shopify_unit_key === $store_weight_unit ) {
			return (float) $weight;
		}

		$conversion_factors = array(
			'kg' => array(
				'kg' => 1,
				'g'  => 1000,
				'lb' => 2.20462,
				'oz' => 35.274,
			),
			'g'  => array(
				'kg' => 0.001,
				'g'  => 1,
				'lb' => 0.00220462,
				'oz' => 0.035274,
			),
			'lb' => array(
				'kg' => 0.453592,
				'g'  => 453.592,
				'lb' => 1,
				'oz' => 16,
			),
			'oz' => array(
				'kg' => 0.0283495,
				'g'  => 28.3495,
				'lb' => 0.0625,
				'oz' => 1,
			),
		);

		if ( ! isset( $conversion_factors[ $shopify_unit_key ][ $store_weight_unit ] ) ) {
			WP_CLI::warning( "Could not find conversion factor from '{$shopify_unit_key}' to '{$store_weight_unit}'. Returning original weight {$weight}." );
			return (float) $weight;
		}

		return (float) $weight * $conversion_factors[ $shopify_unit_key ][ $store_weight_unit ];
	}

	/**
	 * Sets the Woo product brand using 'product_brand' taxonomy.
	 *
	 * @param object     $shopify_product the Shopify product data.
	 * @param WC_Product $product         the Woo product.
	 */
	private function set_woo_product_brand( $shopify_product, $product ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			if ( $this->verbose ) {
				WP_CLI::line( ' - Skipping brand: product_brand taxonomy does not exist.' );
			}
			return;
		}

		$brand = $shopify_product->vendor;
		if ( empty( $brand ) ) {
			if ( $this->verbose ) {
				WP_CLI::line( ' - Skipping brand: Shopify vendor is empty.' );
			}
			return;
		}

		$brand_term = get_term_by( 'name', $brand, 'product_brand' );
		if ( ! $brand_term ) {
			$term_result = wp_insert_term( $brand, 'product_brand' );
			if ( is_wp_error( $term_result ) ) {
				WP_CLI::warning( "Failed to insert brand '{$brand}': " . $term_result->get_error_message() );
				return;
			}
			$brand_term_id = $term_result['term_id'];
			if ( $this->verbose ) {
				WP_CLI::line( " - Created brand '{$brand}' with ID {$brand_term_id}." );
			}
		} else {
			$brand_term_id = $brand_term->term_id;
			if ( $this->verbose ) {
				WP_CLI::line( " - Found existing brand '{$brand}' with ID {$brand_term_id}." );
			}
		}

		wp_set_object_terms( $product->get_id(), $brand_term_id, 'product_brand' );
	}

	/**
	 * Downloads and attaches product images from Shopify URLs.
	 * Uses `media_sideload_image`. Updates `_migration_data['images_mapping']`.
	 *
	 * @param object     $shopify_product the Shopify product data.
	 * @param WC_Product $product         the Woo product.
	 */
	private function upload_images( $shopify_product, $product ) {
		// Check the new 'media' connection
		if ( ! property_exists( $shopify_product, 'media' ) || empty( $shopify_product->media->edges ) ) {
			return;
		}

		if ( $this->verbose ) {
			WP_CLI::line( 'Starting image processing...' );
		}

		if ( ! isset( $this->migration_data['images_mapping'] ) || ! is_array( $this->migration_data['images_mapping'] ) ) {
			$this->migration_data['images_mapping'] = array();
		}

		// Iterate through the 'media' edges
		foreach ( $shopify_product->media->edges as $media_edge ) {
			$media_node = $media_edge->node;

			// Skip if not a MediaImage or doesn't have image data
			if ( ! property_exists( $media_node, 'image' ) || ! is_object( $media_node->image ) || empty( $media_node->id ) ) {
				if ( $this->verbose ) {
					WP_CLI::line( sprintf( ' - Skipping media item (not a valid image or missing ID).' ) );
				}
				continue;
			}

			$image_gql_id = $media_node->id; // Use the MediaImage GQL ID
			$image_url    = $media_node->image->url ?? null;
			$image_alt    = $media_node->image->altText ?? null;

			if ( empty( $image_url ) ) {
				if ( $this->verbose ) {
					WP_CLI::line( sprintf( ' - Skipping image %s: URL is empty.', $image_gql_id ) );
				}
				continue;
			}

			if ( isset( $this->migration_data['images_mapping'][ $image_gql_id ] ) && wp_attachment_is_image( $this->migration_data['images_mapping'][ $image_gql_id ] ) ) {
				if ( $this->verbose ) {
					WP_CLI::line( sprintf( ' - Skipping image %s: Already mapped to attachment ID %s.', $image_gql_id, $this->migration_data['images_mapping'][ $image_gql_id ] ) );
				}
				continue;
			}

			$memory_before = round( memory_get_usage() / 1024 / 1024, 2 );
			$memory_limit  = ini_get( 'memory_limit' );

			if ( $this->verbose ) {
				// Use the image URL from the nested image object
				WP_CLI::line( sprintf('- Uploading image %s from %s... (Memory Usage: %s MB / Limit: %s)', $image_gql_id, $image_url, $memory_before, $memory_limit ) );
			}

			$upload_start_time = microtime( true );
			// Use alt text from the nested image object
			$image_desc        = $image_alt ?: $product->get_name();
			// Use the image URL from the nested image object
			$image_id          = media_sideload_image( $image_url, $product->get_id(), $image_desc, 'id' );
			$upload_duration   = microtime( true ) - $upload_start_time;
			$memory_after      = round( memory_get_usage() / 1024 / 1024, 2 );

			if ( is_wp_error( $image_id ) ) {
				// Use the image URL from the nested image object
				WP_CLI::warning( sprintf( ' - Error uploading %s: %s (Duration: %.2f seconds, Memory after: %s MB)', $image_url, $image_id->get_error_message(), $upload_duration, $memory_after ) );
				continue;
			}

			// Map the MediaImage GQL ID to the WP Attachment ID
			$this->migration_data['images_mapping'][ $image_gql_id ] = $image_id;

			// Use alt text from the nested image object
			if ( $image_alt ) {
				update_post_meta( $image_id, '_wp_attachment_image_alt', $image_alt );
			}

			if ( $this->verbose ) {
				WP_CLI::line( sprintf( ' - Mapped image %s to attachment ID %s. (Upload took %.2f seconds, Memory after: %s MB)', $image_gql_id, $image_id, $upload_duration, $memory_after ) );
			}
		}

		// Save migration data after processing all images for the product
		$product->update_meta_data( '_migration_data', $this->migration_data );
		// Don't save the product here, let the calling function handle it.
	}

	/**
	 * Gets the Woo attachment ID for the Shopify featured image.
	 * Relies on `_migration_data['images_mapping']` being populated by `upload_images`.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return int Attachment ID or 0 if not found/set.
	 */
	private function get_woo_product_image_id( $shopify_product ) {
		// Check the new 'featuredMedia'
		if ( empty( $shopify_product->featuredMedia ) || ! is_object( $shopify_product->featuredMedia ) || empty( $shopify_product->featuredMedia->id ) || empty( $this->migration_data['images_mapping'] ) ) {
			return 0;
		}

		// Get the MediaImage GQL ID from featuredMedia
		$featured_media_gql_id = $shopify_product->featuredMedia->id;

		return $this->migration_data['images_mapping'][ $featured_media_gql_id ] ?? 0;
	}

	/**
	 * Gets the Woo attachment IDs for the product gallery.
	 * Excludes the featured image ID. Relies on `_migration_data['images_mapping']`.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array Array of attachment IDs.
	 */
	private function get_woo_product_gallery_image_ids( $shopify_product ) {
		$gallery_ids = array();
		if ( empty( $this->migration_data['images_mapping'] ) ) {
			return $gallery_ids;
		}

		// This function remains largely the same, as it operates on the WP attachment IDs
		// stored in images_mapping, which is populated by upload_images.
		$featured_image_wp_id = $this->get_woo_product_image_id( $shopify_product );
		$all_wp_image_ids     = array_values( $this->migration_data['images_mapping'] );

		if ( $featured_image_wp_id ) {
			$gallery_ids = array_diff( $all_wp_image_ids, array( $featured_image_wp_id ) );
		} else {
			$gallery_ids = $all_wp_image_ids;
		}

		return array_values( $gallery_ids ); // Ensure keys are re-indexed
	}

	/**
	 * Creates or updates Woo product variations based on Shopify variants.
	 * Handles attributes, variation data (price, sku, stock, weight, image), and mapping.
	 *
	 * @param object     $shopify_product the Shopify product data.
	 * @param WC_Product $product         the Woo (variable) product.
	 */
	private function create_or_update_woo_product_variations( $shopify_product, $product ) {
		$shopify_product_id = basename( $shopify_product->id );
		$attribute_taxonomy_mapping = array();
		$woo_attributes             = array();

		if ( $this->should_process( 'attributes' ) && property_exists( $shopify_product, 'options' ) && ! empty( $shopify_product->options ) ) {
			foreach ( $shopify_product->options as $option ) {
				$taxonomy_slug = sanitize_title( $option->name );
				$taxonomy_name = 'pa_' . $taxonomy_slug;

				if ( ! taxonomy_exists( $taxonomy_name ) ) {
					$attribute_id = wc_create_attribute(
						array(
							'name'         => $option->name,
							'slug'         => $taxonomy_slug,
							'type'         => 'select',
							'order_by'     => 'menu_order',
							'has_archives' => false,
						)
					);
					if ( is_wp_error( $attribute_id ) ) {
						WP_CLI::warning( "Failed to create attribute '{$option->name}': " . $attribute_id->get_error_message() );
						continue;
					}
				} else {
					if ( function_exists( 'wc_attribute_taxonomy_id_by_name' ) ) {
						$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy_name );
					} else {
						$attribute_taxonomy = get_taxonomy( $taxonomy_name );
						$attribute_id       = $attribute_taxonomy ? $attribute_taxonomy->attribute_id : 0;
					}
				}

				$attribute_taxonomy_mapping[ $option->name ] = $taxonomy_name;

				$term_ids = array();
				foreach ( $option->values as $value ) {
					$term_slug = sanitize_title( $value );
					$term      = get_term_by( 'slug', $term_slug, $taxonomy_name );
					if ( ! $term ) {
						$term_result = wp_insert_term( $value, $taxonomy_name, array( 'slug' => $term_slug ) );
						if ( is_wp_error( $term_result ) ) {
							WP_CLI::warning( "Failed to insert term '{$value}' (slug: {$term_slug}) into {$taxonomy_name}: " . $term_result->get_error_message() );
							continue;
						}
						$term_ids[] = $term_result['term_id'];
					} else {
						$term_ids[] = $term->term_id;
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

		if ( ! property_exists( $shopify_product, 'variants' ) || empty( $shopify_product->variants->edges ) ) {
			WP_CLI::warning( "Variable product (ID: {$product->get_id()}) has no variants in Shopify data." );
			return;
		}

		if ( ! isset( $this->migration_data['variations_mapping'] ) || ! is_array( $this->migration_data['variations_mapping'] ) ) {
			$this->migration_data['variations_mapping'] = array();
		}
		$processed_variation_ids = array();

		foreach ( $shopify_product->variants->edges as $variant_edge ) {
			$variant_node    	= $variant_edge->node;
			$variant_gql_id  	= $variant_node->id;
			$shopify_variant_id = basename( $variant_gql_id );
			$variation       	= null;

			if ( isset( $this->migration_data['variations_mapping'][ $variant_gql_id ] ) ) {
				$_variation = wc_get_product( $this->migration_data['variations_mapping'][ $variant_gql_id ] );
				if ( $_variation instanceof WC_Product_Variation && $_variation->get_parent_id() === $product->get_id() ) {
					$variation = $_variation;
				} else {
					unset( $this->migration_data['variations_mapping'][ $variant_gql_id ] );
				}
			}

			if ( ! $variation ) {
				$query_args = array(
					'post_parent' => $product->get_id(),
					'post_type'   => 'product_variation',
					'numberposts' => 1,
					'post_status' => 'any',
					'meta_key'    => '_original_variant_id',
					'meta_value'  => $shopify_variant_id,
					'fields'      => 'ids',
				);
				$found_variation_ids = get_posts( $query_args );
				if ( ! empty( $found_variation_ids ) ) {
					$variation_id = $found_variation_ids[0];
					$variation    = wc_get_product( $variation_id );
					if ( $variation instanceof WC_Product_Variation ) {
						$this->migration_data['variations_mapping'][ $variant_gql_id ] = $variation_id;
					} else {
						WP_CLI::warning( "Found post ID {$variation_id} matching original variant ID {$shopify_variant_id}, but it is not a WC_Product_Variation." );
						$variation = null;
					}
				}
			}

			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product->get_id() );
			}

			$variation->set_menu_order( $variant_node->position );
			$variation->set_status( 'publish' );

			if ( $this->should_process( 'stock' ) ) {
				// Use inventoryItem->tracked to determine if stock is managed
				$manage_stock = property_exists( $variant_node, 'inventoryItem' ) && $variant_node->inventoryItem->tracked;
				$variation->set_manage_stock( $manage_stock );

				// Set stock status based on quantity and inventory policy (if tracked)
				$stock_quantity = $variant_node->inventoryQuantity ?? 0;
				$allow_oversell = $manage_stock && 'CONTINUE' === $variant_node->inventoryPolicy;
				if ( $stock_quantity > 0 || $allow_oversell ) {
					$variation->set_stock_status( 'instock' );
				} else {
					$variation->set_stock_status( 'outofstock' );
				}
				$variation->set_stock_quantity( $stock_quantity );
			}

			if ( $this->should_process( 'weight' ) ) {
				// Get weight and unit from inventoryItem -> measurement -> weight object
				$weight_data = null;
				if ( property_exists( $variant_node, 'inventoryItem' ) && is_object( $variant_node->inventoryItem ) &&
					 property_exists( $variant_node->inventoryItem, 'measurement' ) && is_object( $variant_node->inventoryItem->measurement ) &&
					 property_exists( $variant_node->inventoryItem->measurement, 'weight' ) && is_object( $variant_node->inventoryItem->measurement->weight )
				) {
					$weight_data = $variant_node->inventoryItem->measurement->weight;
				}
				$weight = $weight_data ? $weight_data->value : null;
				$weight_unit = $weight_data ? $weight_data->unit : null;
				$variation->set_weight( $this->get_converted_weight( $weight, $weight_unit ) );
			}

			if ( $this->should_process( 'images' ) && ! empty( $variant_node->media->edges ) ) {
				$variant_media_node = $variant_node->media->edges[0]->node ?? null;

				// Check if it's a valid MediaImage with ID and nested image data
				if ( $variant_media_node && property_exists( $variant_media_node, 'image' ) && is_object( $variant_media_node->image ) && ! empty( $variant_media_node->id ) ) {
					$variant_media_gql_id = $variant_media_node->id; // Use MediaImage GQL ID
					$variant_image_url    = $variant_media_node->image->url ?? null;
					$variant_image_alt    = $variant_media_node->image->altText ?? null;

					if ( isset( $this->migration_data['images_mapping'][ $variant_media_gql_id ] ) ) {
						$variation->set_image_id( $this->migration_data['images_mapping'][ $variant_media_gql_id ] );
					} elseif ( ! empty( $variant_image_url ) ) {
						// Only attempt upload if URL exists and not already mapped
						WP_CLI::line( sprintf( ' - Variant image %s not found in mapping for variation %s. Attempting direct upload...', $variant_media_gql_id, $variant_gql_id ) );
						// Use alt text from nested image object
						$image_desc = $variant_image_alt ?: $product->get_name() . ' - ' . implode( ' / ', wp_list_pluck( $variant_node->selectedOptions, 'value' ) );
						// Use url from nested image object
						$image_id = media_sideload_image( $variant_image_url, $product->get_id(), $image_desc, 'id' );
						if ( ! is_wp_error( $image_id ) ) {
							$variation->set_image_id( $image_id );
							// Add new mapping for this variant image
							$this->migration_data['images_mapping'][ $variant_media_gql_id ] = $image_id;
							$product->update_meta_data( '_migration_data', $this->migration_data ); // Update parent product's meta
						} else {
							// Use url from nested image object
							WP_CLI::warning( sprintf( ' - Failed to upload variant image %s: %s', $variant_image_url, $image_id->get_error_message() ) );
						}
					}
				} else {
					// No valid image media found for variant, unset image ID
					$variation->set_image_id( '' );
				}
			} else {
				// No media connection or not processing images, unset image ID
				$variation->set_image_id( '' );
			}

			if ( $this->should_process( 'price' ) ) {
				if ( $variant_node->compareAtPrice && (float) $variant_node->compareAtPrice > (float) $variant_node->price ) {
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
				} else {
					$variation->set_sku( '' );
				}
			}

			if ( $this->should_process( 'attributes' ) && ! empty( $variant_node->selectedOptions ) ) {
				$variation_attributes = array();
				foreach ( $variant_node->selectedOptions as $selectedOption ) {
					if ( isset( $attribute_taxonomy_mapping[ $selectedOption->name ] ) ) {
						$taxonomy  = $attribute_taxonomy_mapping[ $selectedOption->name ];
						$term_slug = sanitize_title( $selectedOption->value );
						$term      = get_term_by( 'slug', $term_slug, $taxonomy );
						if ( $term instanceof WP_Term ) {
							$variation_attributes[ $taxonomy ] = $term->slug;
						} else {
							WP_CLI::warning( "Could not find term with slug '{$term_slug}' (from value '{$selectedOption->value}') in taxonomy '{$taxonomy}' for variation {$variant_gql_id}." );
						}
					} else {
						WP_CLI::warning( "Attribute taxonomy mapping not found for option '{$selectedOption->name}' while processing variation {$variant_gql_id}." );
					}
				}
				$variation->set_attributes( $variation_attributes );
			}

			// Store original IDs in meta
			$variation->update_meta_data( '_original_variant_id', $shopify_variant_id );
			// Link back to original Shopify product GID's numeric part
			$variation->update_meta_data( '_original_product_id', $shopify_product_id );

			// Save the variation
			$variation_id = $variation->save();
			if ( $variation_id ) {
				$processed_variation_ids[]                              = $variation_id;
				$this->migration_data['variations_mapping'][ $variant_gql_id ] = $variation_id;
				if ( $this->verbose ) {
					WP_CLI::line( sprintf( ' - Saved variation ID %d for Shopify variant %s', $variation_id, $variant_gql_id ) );
				}
			} else {
				WP_CLI::warning( sprintf( ' - Failed to save variation for Shopify variant %s', $variant_gql_id ) );
			}
		}

		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->save();
	}

	/**
	 * Updates the SEO title/description for a product using Yoast SEO meta fields.
	 * Uses 'global.title_tag' and 'global.description_tag' metafields if available.
	 *
	 * @param object     $shopify_product the Shopify product data.
	 * @param WC_Product $product         the Woo product.
	 */
	private function update_seo_title_description( $shopify_product, WC_Product $product ) {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		$seo_title       = null;
		$seo_description = null;

		if ( property_exists( $shopify_product, 'metafields' ) && ! empty( $shopify_product->metafields->edges ) ) {
			foreach ( $shopify_product->metafields->edges as $edge ) {
				$field_node = $edge->node;
				if ( 'global' === $field_node->namespace && 'title_tag' === $field_node->key && ! empty( $field_node->value ) ) {
					$seo_title = $field_node->value;
				}
				if ( 'global' === $field_node->namespace && 'description_tag' === $field_node->key && ! empty( $field_node->value ) ) {
					$seo_description = $field_node->value;
				}
			}
		}

		$final_seo_title = $seo_title ?: $product->get_name();
		$final_seo_description = $seo_description ?: ( $product->get_description() ?: wp_strip_all_tags( $product->get_short_description() ) );

		$current_seo_title       = $product->get_meta( '_yoast_wpseo_title' );
		$current_seo_description = $product->get_meta( '_yoast_wpseo_metadesc' );

		if ( $current_seo_title !== $final_seo_title ) {
			$product->update_meta_data( '_yoast_wpseo_title', $final_seo_title );
			if ( $this->verbose ) {
				WP_CLI::line( " - Updating Yoast title to: {$final_seo_title}" );
			}
		}
		if ( $current_seo_description !== $final_seo_description ) {
			$product->update_meta_data( '_yoast_wpseo_metadesc', $final_seo_description );
			if ( $this->verbose ) {
				WP_CLI::line( " - Updating Yoast description to: {$final_seo_description}" );
			}
		}
	}

	/**
	 * Deletes WooCommerce variations that are children of the product but were not
	 * in the set of processed variations for the current import run.
	 * Only runs if `--remove-orphans` argument is present.
	 *
	 * @param WC_Product $product                 The parent variable product.
	 * @param array      $processed_variation_ids Array of WC variation IDs that were processed.
	 */
	private function clean_up_orphan_variations( $product, $processed_variation_ids ) {
		if ( ! isset( $this->assoc_args['remove-orphans'] ) ) {
			return;
		}

		$existing_variations = $product->get_children();
		$orphans             = array_diff( $existing_variations, $processed_variation_ids );

		if ( ! empty( $orphans ) ) {
			WP_CLI::line( 'Removing ' . count( $orphans ) . ' orphan variations...' );
			foreach ( $orphans as $orphan_id ) {
				$variation_obj = wc_get_product( $orphan_id );
				if ( $variation_obj instanceof WC_Product_Variation ) {
					$variation_obj->delete( true );
					WP_CLI::line( ' - Removed orphan variation ID: ' . $orphan_id );
				} else {
					WP_CLI::warning( ' - Tried to remove orphan variation ID ' . $orphan_id . ', but it was not a valid variation product.' );
				}
			}
			wc_delete_product_transients( $product->get_id() );
		} elseif ( $this->verbose ) {
			WP_CLI::line( ' - No orphan variations found to remove.' );
		}
	}

	/**
	 * Fetches total product count from Shopify REST API (as estimate).
	 * GraphQL doesn't easily provide counts matching complex filters.
	 * REST count endpoint has limited filter support.
	 *
	 * @param array $assoc_args Command-line arguments provided to the CLI command.
	 * @return int|null Total product count, or null if count couldn't be determined.
	 */
	private function fetch_total_product_count( $assoc_args ) {
		$count_params = array();
		if ( isset( $assoc_args['status'] ) && in_array( strtoupper( $assoc_args['status'] ), array( 'ACTIVE', 'ARCHIVED', 'DRAFT' ) ) ) {
			$count_params['status'] = strtolower( $assoc_args['status'] );
		}
		if ( isset( $assoc_args['after'] ) ) {
			$count_params['created_at_min'] = $assoc_args['after'];
		}
		if ( isset( $assoc_args['before'] ) ) {
			$count_params['created_at_max'] = $assoc_args['before'];
		}

		WP_CLI::line( 'Fetching estimated product count from Shopify REST API (may not reflect all GraphQL filters)...' );
		if ( ! empty( $count_params ) && $this->verbose ) {
			WP_CLI::line( ' - Using REST count parameters: ' . wp_json_encode( $count_params ) );
		} elseif ( $this->verbose ) {
			WP_CLI::line( ' - No compatible REST count parameters detected.' );
		}

		$response = Migrator_CLI_Utils::rest_request( 'products/count.json', $count_params );

		if ( $response && isset( $response->data->count ) && is_numeric( $response->data->count ) ) {
			$count = (int) $response->data->count;
			WP_CLI::line( sprintf( 'Estimated total products found matching REST filters: %d', $count ) );
			return $count;
		} else {
			$error_message = 'Could not fetch estimated total product count.';
			if ( $response && isset( $response->error ) ) {
				$error_message .= ' API Error: ' . $response->error;
			} elseif ( ! $response ) {
				$error_message .= ' Request failed.';
			} else {
				$error_message .= ' Unexpected response structure.';
			}
			WP_CLI::warning( $error_message . ' Progress bar may not show percentage.' );
			return null;
		}
	}
}

