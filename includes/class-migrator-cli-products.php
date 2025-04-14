<?php

class Migrator_CLI_Products {
	private $fields;
	private $additional_product_data;
	private $migration_data;
	private $batch_count;
	private $total_products_processed;
	private $total_products_created;
	private $total_products_updated;
	private $total_products_skipped;

	/**
	 * Migrates products from Shopify into Woo.
	 *
	 * @param $assoc_args ['before'] ['after'] ['limit'] ['perpage'] ['next'] ['status'] ['status'] ['ids'] ['exclude'] ['handle'] ['product-type'] ['product-type'] ['no-update']
	 */
	public function migrate_products( $assoc_args ) {
		WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Starting product migration...' );
		$start_time = microtime(true);
		Migrator_CLI_Utils::health_check();

		// Initialize counters for current batch
		$batch_products_processed = 0;
		$batch_products_created = 0;
		$batch_products_updated = 0;
		$batch_products_skipped = 0;

		// Initialize or increment batch counter
		if (!isset($this->batch_count)) {
			$this->batch_count = 1;
			// Initialize overall statistics as class properties
			$this->total_products_processed = 0;
			$this->total_products_created = 0;
			$this->total_products_updated = 0;
			$this->total_products_skipped = 0;
		} else {
			$this->batch_count++;
		}

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

		$before       = isset( $assoc_args['before'] ) ? $assoc_args['before'] : null;
		$after        = isset( $assoc_args['after'] ) ? $assoc_args['after'] : null;
		$limit        = isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : PHP_INT_MAX;
		$perpage      = isset( $assoc_args['perpage'] ) ? $assoc_args['perpage'] : 50;
		$perpage      = min( $perpage, $limit );
		$next_link    = isset( $assoc_args['next'] ) ? $assoc_args['next'] : '';
		$status       = isset( $assoc_args['status'] ) ? $assoc_args['status'] : 'active';
		$ids          = isset( $assoc_args['ids'] ) ? $assoc_args['ids'] : null;
		$exclude      = isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array();
		$handle       = isset( $assoc_args['handle'] ) ? $assoc_args['handle'] : null;
		$product_type = isset( $assoc_args['product-type'] ) ? $assoc_args['product-type'] : 'all';
		$no_update    = isset( $assoc_args['no-update'] ) ? true : false;

		$api_start_time = microtime(true);
		if ( $next_link ) {
			$response_data = Migrator_CLI_Utils::rest_request( $next_link );
		} else {
			$response_data = Migrator_CLI_Utils::rest_request(
				'products.json',
				array(
					'limit'          => $perpage,
					'created_at_max' => $before,
					'created_at_min' => $after,
					'status'         => $status,
					'ids'            => $ids,
					'handle'         => $handle,
				)
			);
		}
		$api_time = microtime(true) - $api_start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'API request completed in %.4f seconds', $api_time ) );

		if ( ! $response_data || empty( $response_data->data->products ) ) {
			WP_CLI::error( 'No Shopify products found.' );
		}

		$products_in_batch = count( $response_data->data->products );
		$products_to_process = min( $limit, $perpage, $products_in_batch );
		
		WP_CLI::line( sprintf( 'Found %d products in Shopify. Processing %d products (Batch #%d).', 
			$products_in_batch, $products_to_process, $this->batch_count ) );

		foreach ( $response_data->data->products as $shopify_product ) {
			$product_start_time = microtime(true);

			if ( in_array( $shopify_product->id, $exclude, true ) || $this->preg_match_array( $shopify_product->variants[0]->sku, $exclude ) ) {
				WP_CLI::line( sprintf( 'Product %s is excluded. Skipping...', $shopify_product->handle ) );
				$batch_products_skipped++;
				$this->total_products_skipped++;
				continue;
			}

			WP_CLI::line( 'Fetching additional product data...' );
			$fetch_start_time = microtime(true);
			$this->fetch_additional_shopify_product_data( $shopify_product );
			$fetch_time = microtime(true) - $fetch_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Additional data fetched in %.4f seconds', $fetch_time ) );

			// Check if product is single or variable by checking how many
			// variants it has.
			$curent_product_type = 'single';
			if ( $this->is_variable_product( $shopify_product ) ) {
				$curent_product_type = 'variable';
			}

			if ( 'all' !== $product_type && $product_type !== $curent_product_type ) {
				WP_CLI::line( sprintf( 'Product %s is %s. Skipping...', $shopify_product->handle, $curent_product_type ) );
				$batch_products_skipped++;
				$this->total_products_skipped++;
				continue;
			}

			// Check if the product already exists in Woo by handle.
			$lookup_start_time = microtime(true);
			$woo_product = $this->get_corresponding_woo_product( $shopify_product );
			$lookup_time = microtime(true) - $lookup_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Product lookup completed in %.4f seconds', $lookup_time ) );

			if ( $woo_product ) {
				WP_CLI::line( sprintf( 'Product %s already exists (%s). %s...', $shopify_product->handle, $woo_product->get_id(), $no_update ? 'Skipping' : 'Updating' ) );

				if ( $no_update ) {
					$batch_products_skipped++;
					$this->total_products_skipped++;
					continue;
				} else {
					$batch_products_updated++;
					$this->total_products_updated++;
				}
			} else {
				WP_CLI::line( sprintf( 'Product %s does not exist. Creating...', $shopify_product->handle ) );
				$batch_products_created++;
				$this->total_products_created++;
			}

			$create_update_start_time = microtime(true);
			$this->create_or_update_woo_product( $shopify_product, $woo_product );
			$create_update_time = microtime(true) - $create_update_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Product creation/update completed in %.4f seconds', $create_update_time ) );
			
			$product_total_time = microtime(true) - $product_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Total processing time for product %s: %.4f seconds', $shopify_product->handle, $product_total_time ) );
			
			$batch_products_processed++;
			$this->total_products_processed++;
			
			WP_CLI::line( sprintf( 'Product %d/%d in current batch completed', $batch_products_processed, $products_to_process ) );
		}

		// Display batch statistics
		WP_CLI::line( '===============================' );
		WP_CLI::line( WP_CLI::colorize( '%BStats for batch #' . $this->batch_count . ':%n' ) );
		WP_CLI::line( sprintf( 'Products processed: %d', $batch_products_processed ) );
		WP_CLI::line( sprintf( 'Products created: %d', $batch_products_created ) );
		WP_CLI::line( sprintf( 'Products updated: %d', $batch_products_updated ) );
		WP_CLI::line( sprintf( 'Products skipped: %d', $batch_products_skipped ) );
		WP_CLI::line( '===============================' );

		$next_link = $response_data->next_link;
		if ( $next_link && $limit > $perpage ) {
			Migrator_CLI_Utils::reset_in_memory_cache();
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'There are more products to process.' );
			$this->migrate_products(
				array(
					'next'    => $next_link,
					'limit'   => $limit - $perpage,
					'exclude' => implode( ',', $exclude ),
				)
			);
		} else {
			$total_time = microtime(true) - $start_time;
			
			// Display overall statistics
			WP_CLI::line( '===============================' );
			WP_CLI::line( WP_CLI::colorize( '%BOverall migration stats:%n' ) );
			WP_CLI::line( sprintf( 'Total API calls: %d', $this->batch_count ) );
			WP_CLI::line( sprintf( 'Total products processed: %d', $this->total_products_processed ) );
			WP_CLI::line( sprintf( 'Total products created: %d', $this->total_products_created ) );
			WP_CLI::line( sprintf( 'Total products updated: %d', $this->total_products_updated ) );
			WP_CLI::line( sprintf( 'Total products skipped: %d', $this->total_products_skipped ) );
			WP_CLI::line( sprintf( 'Products per second: %.2f', $this->total_products_processed / $total_time ) );
			WP_CLI::line( '===============================' );
			
			WP_CLI::success( sprintf( 'All products have been processed in %.4f seconds.', $total_time ) );
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

	/**
	 * Supports matching against an array of regular expressions, and will do a glob match so things like CANAL_* will match every product that starts with CANAL_.
	 *
	 * @param string $subject Product SKU.
	 * @param array  $patterns Array of patterns to match against.
	 * @return bool
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
	 * Fetches additional product data from Shopify and saves it to $this->additional_product_data.
	 *
	 * @param object $shopify_product the Shopify product data.
	 */
	private function fetch_additional_shopify_product_data( $shopify_product ) {
		$start_time = microtime(true);
		$response = Migrator_CLI_Utils::graphql_request(
			array(
				'query' => 'query {
				product(id: "gid://shopify/Product/' . $shopify_product->id . '") {
					id
					handle
					onlineStoreUrl
					collections(first: 100) {
						edges {
							node {
								id
								title
								handle
							}
						}
					}
					metafields(first: 100) {
						edges {
							node {
								key
								namespace
								value
							}
						}
					}
				}
			}',
			)
		);

		$response_data = json_decode( wp_remote_retrieve_body( $response ) );

		$this->additional_product_data = $response_data->data->product;
		$execution_time = microtime(true) - $start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'GraphQL request completed in %.4f seconds', $execution_time ) );
	}

	/**
	 * Checks if the product contains variants.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return bool
	 */
	private function is_variable_product( $shopify_product ) {
		return count( $shopify_product->variants ) > 1;
	}

	/**
	 * Gets the Woo product that matches the Shopify product id.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return WC_Product|null
	 */
	private function get_corresponding_woo_product( $shopify_product ) {
		// Try finding the product by original Shopify product ID.
		$woo_products = wc_get_products(
			array(
				'limit'      => 1,
				'meta_key'   => '_original_product_id',
				'meta_value' => $shopify_product->id,
			)
		);

		if ( count( $woo_products ) === 1 && is_a( $woo_products[0], 'WC_Product' ) ) {
			return wc_get_product( $woo_products[0] );
		}
	}

	/**
	 * Creates or updates the Woo product.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @param WC_Product $woo_product the Woo product.
	 */
	private function create_or_update_woo_product( $shopify_product, $woo_product = null ) {
		$start_time = microtime(true);
		$this->migration_data = array(
			'product_id'         => $shopify_product->id,
			'original_url'       => '',
			'images_mapping'     => array(),
			'metafields'         => array(),
			'variations_mapping' => array(),
		);

		if ( $woo_product ) {
			$saved_migration_data = $woo_product->get_meta( '_migration_data' );
			if ( $saved_migration_data ) {
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
			$product->set_description( $this->sanitize_product_description( $shopify_product->body_html ) );
		}

		if ( $this->should_process( 'status' ) ) {
			$product->set_status( $this->get_woo_product_status( $shopify_product ) );
		}

		if ( $this->should_process( 'date_created' ) ) {
			$product->set_date_created( $shopify_product->created_at );
		}

		if ( $this->should_process( 'catalog_visibility' ) ) {
			if ( $this->additional_product_data && property_exists( $this->additional_product_data, 'onlineStoreUrl' ) ) {
				if ( null === $this->additional_product_data->onlineStoreUrl ) {
					$product->set_catalog_visibility( 'hidden' );
				} else {
					$this->migration_data['original_url'] = $this->additional_product_data->onlineStoreUrl;
				}
			}
		}

		if ( $this->should_process( 'category' ) ) {
			$category_start_time = microtime(true);
			$product->set_category_ids( $this->get_woo_product_category_ids() );
			$category_time = microtime(true) - $category_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Category processing completed in %.4f seconds', $category_time ) );
		}

		if ( $this->should_process( 'tag' ) ) {
			$tag_start_time = microtime(true);
			$product->set_tag_ids( $this->get_woo_product_tag_ids( $shopify_product ) );
			$tag_time = microtime(true) - $tag_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Tag processing completed in %.4f seconds', $tag_time ) );
		}

		// Simple product.
		if ( ! $this->is_variable_product( $shopify_product ) ) {
			if ( $this->should_process( 'price' ) ) {
				if ( $shopify_product->variants[0]->compare_at_price && $shopify_product->variants[0]->compare_at_price > $shopify_product->variants[0]->price ) {
					$product->set_sale_price( $shopify_product->variants[0]->price );
					$product->set_regular_price( $shopify_product->variants[0]->compare_at_price );
				} else {
					$product->set_sale_price( '' );
					$product->set_regular_price( $shopify_product->variants[0]->price );
				}
			}
			if ( $this->should_process( 'sku' ) ) {
				// Prevents errors when there are products with duplicated SKUs.
				add_filter( 'wc_product_has_unique_sku', '__return_false', 10, 3 );
				$product->set_sku( $shopify_product->variants[0]->sku );
				remove_filter( 'wc_product_has_unique_sku', '__return_false' );
			}
			if ( $this->should_process( 'stock' ) ) {
				$product->set_manage_stock( 'shopify' === $shopify_product->variants[0]->inventory_management );
				$product->set_stock_status( 'deny' === $shopify_product->variants[0]->inventory_quantity ? 'outofstock' : 'instock' );
				$product->set_stock_quantity( $shopify_product->variants[0]->inventory_quantity );
			}
			if ( $this->should_process( 'weight' ) ) {
				$product->set_weight( $this->get_converted_weight( $shopify_product->variants[0]->weight, $shopify_product->variants[0]->weight_unit ) );
			}
			$product->update_meta_data( '_original_variant_id', $shopify_product->variants[0]->id );
		} else {
			$product->set_sku( '' );
		}

		// The operations below require product id, so we need to save the
		// product first.
		$save_start_time = microtime(true);
		$product->save();
		$save_time = microtime(true) - $save_start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Initial product save completed in %.4f seconds', $save_time ) );

		// Product brand
		if ( $this->should_process( 'brand' ) ) {
			$brand_start_time = microtime(true);
			$this->set_woo_product_brand( $shopify_product, $product );
			$brand_time = microtime(true) - $brand_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Setting product brand completed in %.4f seconds', $brand_time ) );
		}

		// Process images.
		if ( $this->should_process( 'images' ) ) {
			$images_start_time = microtime(true);
			$this->upload_images( $shopify_product, $product );
			$product->set_image_id( $this->get_woo_product_image_id( $shopify_product ) );
			$product->set_gallery_image_ids( $this->get_woo_product_gallery_image_ids( $shopify_product ) );
			$images_time = microtime(true) - $images_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Image processing completed in %.4f seconds', $images_time ) );
		}

		$save_start_time = microtime(true);
		$product->save();
		$save_time = microtime(true) - $save_start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Product save after images completed in %.4f seconds', $save_time ) );

		// Variations.
		if ( $this->is_variable_product( $shopify_product ) ) {
			$variations_start_time = microtime(true);
			$this->create_or_update_woo_product_variations( $shopify_product, $product );
			$variations_time = microtime(true) - $variations_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Variations processing completed in %.4f seconds', $variations_time ) );
		}

		if ( $this->should_process( 'seo' ) ) {
			$seo_start_time = microtime(true);
			$this->update_seo_title_description( $shopify_product, $product );
			$seo_time = microtime(true) - $seo_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'SEO update completed in %.4f seconds', $seo_time ) );
		}

		// Migration metas.
		foreach ( $this->additional_product_data->metafields->edges as $field ) {
			$key                                        = sprintf( '%s_%s', $field->node->namespace, $field->node->key );
			$this->migration_data['metafields'][ $key ] = $field->node->value;
		}

		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->update_meta_data( '_original_product_id', $shopify_product->id ); // For searching later

		$final_save_start_time = microtime(true);
		$product->save();
		$final_save_time = microtime(true) - $final_save_start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Final product save completed in %.4f seconds', $final_save_time ) );
		
		WP_CLI::line( 'Woo Product ID: ' . $product->get_id() );
		
		$total_time = microtime(true) - $start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Total create/update time: %.4f seconds', $total_time ) );
	}

	/**
	 * Checks if the field is contained in the $this->fields array.
	 *
	 * @param string $field the field to be checked.
	 * @return bool
	 */
	private function should_process( $field ) {
		return in_array( $field, $this->fields, true );
	}

	/**
	 * Sanitizes the product description html.
	 *
	 * @param string $html the product description html.
	 * @return string sanitized description.
	 */
	private function sanitize_product_description( $html ) {
		if ( ! $html ) {
			return '';
		}
		
		$html = htmlspecialchars_decode(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));

		$html = preg_replace( '~<script(.*?)</script>~Usi', '', $html );
		$html = preg_replace( '~<style(.*?)</style>~Usi', '', $html );
		$html = wp_kses_post( $html );
		$html = trim( $html );

		return $html;
	}

	/**
	 * Converts the Shopify product status into Woo product status.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return string the Woo product status.
	 */
	private function get_woo_product_status( $shopify_product ) {
		$woo_product_status = 'draft';

		if ( 'active' === $shopify_product->status ) {
			$woo_product_status = 'publish';
		}

		return $woo_product_status;
	}

	/**
	 * Gets the Woo product category ids that match the collection handle in
	 * $this->additional_product_data->collections->edges[collection]->node->handle
	 *
	 * @return array
	 */
	private function get_woo_product_category_ids() {
		$start_time = microtime(true);
		$category_ids = array();
		$collections  = $this->additional_product_data->collections->edges;

		foreach ( $collections as $collection ) {
			// Check if the category exists in WooCommerce.
			$woo_product_category = get_term_by( 'slug', $collection->node->handle, 'product_cat', ARRAY_A );

			// If the category doesn't exist, create it.
			if ( ! $woo_product_category ) {
				$woo_product_category = wp_insert_term(
					$collection->node->title,
					'product_cat',
					array(
						'slug' => $collection->node->handle,
					)
				);
			}

			$category_ids[] = $woo_product_category['term_id'];
		}

		if ( empty( $category_ids ) ) {
			$category_ids[] = get_option( 'default_product_cat' );
		}

		$execution_time = microtime(true) - $start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Category processing completed in %.4f seconds', $execution_time ) );
		
		return $category_ids;
	}

	/**
	 * Gets the Woo product tags ids that match the Shopify product tags.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array
	 */
	private function get_woo_product_tag_ids( $shopify_product ) {
		$start_time = microtime(true);
		$tag_ids = array();

		$tags = $shopify_product->tags;

		if ( ! $tags ) {
			return $tag_ids;
		}

		$tags = explode( ',', $tags );
		$tags = array_map( 'trim', $tags );

		foreach ( $tags as $tag ) {
			// Check if the tag exists in WooCommerce.
			$woo_product_tag = get_term_by( 'slug', $tag, 'product_tag', ARRAY_A );

			// If the tag doesn't exist, create it.
			if ( ! $woo_product_tag ) {
				$woo_product_tag = wp_insert_term(
					$tag,
					'product_tag',
					array(
						'slug' => $tag,
					)
				);
			}

			$tag_ids[] = $woo_product_tag['term_id'];
		}

		$execution_time = microtime(true) - $start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Tag processing completed in %.4f seconds', $execution_time ) );
		
		return $tag_ids;
	}

	/**
	 * Returns a conversion table for a given weight.
	 *
	 * @param float $weight the old weight.
	 * @param string $weight_unit the original unit.
	 * @return float
	 */
	private function get_converted_weight( $weight, $weight_unit ) {
		$store_weight_unit = get_option( 'woocommerce_weight_unit' );
		if ( 'lbs' === $store_weight_unit ) {
			$store_weight_unit = 'lb';
		}

		$conversion = array(
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

		return $weight * $conversion[ $weight_unit ][ $store_weight_unit ];
	}

	/**
	 * Sets the Woo product brand.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @param WC_Product $product the Woo product.
	 */
	private function set_woo_product_brand( $shopify_product, $product ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return;
		}

		$brand = $shopify_product->vendor;

		if ( ! $brand ) {
			return;
		}

		// Check if the brand exists in WooCommerce.
		$woo_product_brand = get_term_by( 'name', $brand, 'product_brand', ARRAY_A );

		// If the brand doesn't exist, create it.
		if ( ! $woo_product_brand ) {
			$woo_product_brand = wp_insert_term(
				$brand,
				'product_brand'
			);
		}

		// Assign the brand to the product.
		wp_set_object_terms( $product->get_id(), $woo_product_brand['term_id'], 'product_brand' );
	}

	/**
	 * Saves product images.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @param WC_Product $product the Woo product.
	 */
	private function upload_images( $shopify_product, $product ) {
		$start_time = microtime(true);
		$image_count = 0;
		$skipped_count = 0;
		
		foreach ( $shopify_product->images as $image ) {
			// Check if the image has already been uploaded.
			if ( isset( $this->migration_data['images_mapping'][ $image->id ] ) && wp_attachment_is_image( $this->migration_data['images_mapping'][ $image->id ] ) ) {
				$skipped_count++;
				continue;
			}

			$upload_start = microtime(true);
			// Upload the image to the media library.
			$image_id = media_sideload_image( $image->src, $product->get_id(), '', 'id' );
			$upload_time = microtime(true) - $upload_start;
			
			if ( is_wp_error( $image_id ) ) {
				WP_CLI::line( sprintf( 'Error uploading %s: %s', $image->src, $image_id->get_error_message() ) );
			} else {
				WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Image upload completed in %.4f seconds', $upload_time ) );
				$image_count++;
			}

			// Save the mapping.
			$this->migration_data['images_mapping'][ $image->id ] = $image_id;
		}

		$this->migration_data['images_mapping'] = $this->migration_data['images_mapping'];
		
		$total_time = microtime(true) - $start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'All images processed in %.4f seconds. Uploaded: %d, Skipped: %d', $total_time, $image_count, $skipped_count ) );
	}

	/**
	 * Gets the Woo product image id that matches the first Shopify product image id.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return int
	 */
	private function get_woo_product_image_id( $shopify_product ) {
		if ( empty( $shopify_product->images ) ) {
			return 0;
		}

		// Get the image ID from mapping.
		return $this->migration_data['images_mapping'][ $shopify_product->images[0]->id ];
	}

	/**
	 * Gets the Woo product gallery image ids
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array
	 */
	private function get_woo_product_gallery_image_ids( $shopify_product ) {
		if ( count( $this->migration_data['images_mapping'] ) < 2 ) {
			return array();
		}

		return array_diff( array_values( $this->migration_data['images_mapping'] ), array( $this->get_woo_product_image_id( $shopify_product, $this->migration_data['images_mapping'] ) ) );
	}

	/**
	 * Creates or updates Woo product variations.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @param WC_Product $product the Woo product.
	 */
	private function create_or_update_woo_product_variations( $shopify_product, $product ) {
		$start_time = microtime(true);
		$attribute_taxonomy_mapping = array();

		if ( $this->should_process( 'attributes' ) ) {
			$attr_start_time = microtime(true);
			// Create attribute taxonomies if needed.
			foreach ( $shopify_product->options as $option ) {
				// Check if the attribute taxonomy exists in WooCommerce.
				if ( ! taxonomy_exists( 'pa_' . sanitize_title( $option->name ) ) ) {
					wc_create_attribute(
						array(
							'name'     => $option->name,
							'slug'     => sanitize_title( $option->name ),
							'type'     => 'select',
							'order_by' => 'menu_order',
						)
					);
				}

				$attribute_taxonomy_mapping[ 'option' . $option->position ] = 'pa_' . sanitize_title( $option->name );
			}

			$attributes_data = array();

			// Force update the taxonomies registration.
			unregister_taxonomy( 'product_type' );
			WC_Post_Types::register_taxonomies();

			foreach ( $attribute_taxonomy_mapping as $attribute_taxonomy ) {
				$attributes_data[ $attribute_taxonomy ] = array();
			}

			// Get available value from variants option
			foreach ( $shopify_product->variants as $variant ) {
				foreach ( $attribute_taxonomy_mapping as $option_key => $attribute_taxonomy ) {
					// Create the attribute term if it doesn't exist.
					$slug = sanitize_title( $variant->$option_key );
					$term = get_term_by( 'slug', $slug, $attribute_taxonomy, ARRAY_A );
					if ( ! $term ) {
						$term = wp_insert_term(
							$variant->$option_key,
							$attribute_taxonomy,
							array(
								'slug' => $slug,
							)
						);
					}
					$attributes_data[ $attribute_taxonomy ][] = $term['term_id'];
				}
			}

			$attributes = array_map(
				function ( $taxonomy, $value ) {
					$attribute = new WC_Product_Attribute();
					$attribute->set_name( $taxonomy );
					$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
					$attribute->set_options( $value );
					$attribute->set_visible( true );
					$attribute->set_variation( true );
					return $attribute;
				},
				array_keys( $attributes_data ),
				array_values( $attributes_data )
			);

			$product->set_attributes( $attributes );
			$attr_save_start = microtime(true);
			$product->save();
			$attr_save_time = microtime(true) - $attr_save_start;
			
			$attr_time = microtime(true) - $attr_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Attribute processing completed in %.4f seconds (save: %.4f seconds)', $attr_time, $attr_save_time ) );
		}

		$variants_count = 0;
		$variants_start_time = microtime(true);
		
		foreach ( $shopify_product->variants as $variant ) {
			$variant_start_time = microtime(true);
			WP_CLI::line( 'Processing variant ' . $variant->id );
			$variation = new WC_Product_Variation();

			// Check if the variant has been handled by our migrator before.
			if ( in_array( $variant->id, array_keys( $this->migration_data['variations_mapping'] ), true ) ) {
				$_variation = wc_get_product( $this->migration_data['variations_mapping'][ $variant->id ] );
				if ( is_a( $_variation, 'WC_Product_Variation' ) ) {
					$variation = $_variation;
					WP_CLI::line( 'Found existing variation (ID: ' . $variation->get_id() . '). Updating.' );
				}
			} else {
				$check_product = wc_get_products(
					array(
						'limit'      => 1,
						'meta_key'   => '_original_variant_id',
						'meta_value' => $variant->id,
					)
				);

				if ( is_a( $check_product, 'WC_Product_Variation' ) ) {
					// The product is already a variation.
					$variation = new WC_Product_Variation( $check_product );
					WP_CLI::line( 'Found existing variation (id: ' . $variation->get_id() . '). Updating.' );
				} elseif ( is_a( $check_product, 'WC_Product' ) ) {
					WP_CLI::error( 'A product variation id was set to a Simple Product. This should not happen. Variation id: ' . $variant->id );
				}
			}

			$variation->set_parent_id( $product->get_id() );
			$variation->set_menu_order( $variant->position );
			$variation->set_status( 'publish' );

			if ( $this->should_process( 'stock' ) ) {
				$variation->set_manage_stock( 'shopify' === $variant->inventory_management );
				$variation->set_stock_quantity( $variant->inventory_quantity );
				$variation->set_stock_status( 'deny' === $variant->inventory_quantity ? 'outofstock' : 'instock' );
			}

			if ( $this->should_process( 'weight' ) ) {
				$variation->set_weight( $this->get_converted_weight( $variant->weight, $variant->weight_unit ) );
			}

			if ( $this->should_process( 'images' ) ) {
				if ( $variant->image_id ) {
					$variation->set_image_id( $this->migration_data['images_mapping'][ $variant->image_id ] );
				}
			}

			if ( $this->should_process( 'price' ) ) {
				if ( $variant->compare_at_price && $variant->compare_at_price > $variant->price ) {
					$variation->set_regular_price( $variant->compare_at_price );
					$variation->set_sale_price( $variant->price );
				} else {
					$variation->set_regular_price( $variant->price );
					$variation->set_sale_price( '' );
				}
			}

			if ( $this->should_process( 'sku' ) ) {
				if ( $variant->sku ) {
					// Prevents errors when there are products with duplicated SKUs.
					add_filter( 'wc_product_has_unique_sku', '__return_false', 10, 3 );
					$variation->set_sku( $variant->sku );
					remove_filter( 'wc_product_has_unique_sku', '__return_false' );
				}
			}

			if ( $this->should_process( 'attributes' ) ) {
				$variation_attributes = array();
				foreach ( $attribute_taxonomy_mapping as $option_key => $attribute_taxonomy ) {
					$attribute                                   = get_term_by( 'name', $variant->$option_key, $attribute_taxonomy );
					$variation_attributes[ $attribute_taxonomy ] = $attribute->slug;
				}

				$variation->set_attributes( $variation_attributes );
			}

			// Save the variant ID to the variation meta data.
			$variation->update_meta_data( '_original_variant_id', $variant->id );
			$variation->update_meta_data( '_original_product_id', $variant->product_id );

			$variation_save_start = microtime(true);
			$variation->save();
			$variation_save_time = microtime(true) - $variation_save_start;

			$this->migration_data['variations_mapping'][ $variant->id ] = $variation->get_id();
			$variants_count++;
			
			$variant_time = microtime(true) - $variant_start_time;
			WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Variation processing completed in %.4f seconds (save: %.4f seconds)', $variant_time, $variation_save_time ) );
		}
		
		$variants_time = microtime(true) - $variants_start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'All %d variations processed in %.4f seconds', $variants_count, $variants_time ) );

		$this->clean_up_orphan_variations( $product );
		
		$total_time = microtime(true) - $start_time;
		WP_CLI::line( WP_CLI::colorize( '%BPerformance:%n ' ) . sprintf( 'Total variations processing time: %.4f seconds', $total_time ) );
	}

	/**
	 * Updates the SEO tittle description for a product.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @param WC_Product $product the Woo product.
	 */
	private function update_seo_title_description( $shopify_product, WC_Product $product ) {
		$current_seo_title       = $product->get_meta( '_yoast_wpseo_title' );
		$current_seo_description = $product->get_meta( '_yoast_wpseo_metadesc' );

		$title       = $product->get_title();
		$description = $product->get_short_description() ? $product->get_short_description() : get_the_excerpt( $product->get_id() );

		foreach ( $this->additional_product_data->metafields->edges as $field ) {
			if ( 'global' === $field->node->namespace && 'title_tag' === $field->node->key ) {
				$title = $field->node->value;
			}

			if ( 'global' === $field->node->namespace && 'description_tag' === $field->node->key ) {
				$description = $field->node->value;
			}
		}

		if ( $current_seo_title !== $title ) {
			$product->update_meta_data( '_yoast_wpseo_title', $title );
		}

		if ( $current_seo_description !== $description ) {
			$product->update_meta_data( '_yoast_wpseo_metadesc', $description );
		}

		$product->save();
	}

	/**
	 * Removes variations that were not added during this run.
	 *
	 * @param WC_Product $product the Woo product.
	 */
	private function clean_up_orphan_variations( $product ) {
		if ( ! isset( $this->assoc_args['remove-orphans'] ) ) {
			return;
		}

		$variations = $product->get_children();

		foreach ( $variations as $variation_id ) {
			if ( ! in_array( $variation_id, array_values( $this->migration_data['variations_mapping'] ), true ) ) {
				WP_CLI::line( 'Deleting orphan variation (ID: ' . $variation_id . ')' );
				wp_delete_post( $variation_id, true );
			}
		}
	}
}
