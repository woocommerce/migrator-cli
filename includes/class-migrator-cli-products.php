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

	/**
	 * Migrates products from Shopify into Woo.
	 *
	 * @param $assoc_args ['before'] ['after'] ['limit'] ['perpage'] ['next'] ['status'] ['status'] ['ids'] ['exclude'] ['handle'] ['product-type'] ['product-type'] ['no-update']
	 */
	public function migrate_products( $assoc_args ) {
		Migrator_CLI_Utils::health_check();
		$this->assoc_args = $assoc_args; // Store assoc_args for helper methods

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

		// --- GraphQL Migration Logic ---
		$limit        = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : PHP_INT_MAX;
		$perpage      = isset( $assoc_args['perpage'] ) ? min( (int) $assoc_args['perpage'], 250 ) : 50; // Max 250, default 50
		$no_update    = isset( $assoc_args['no-update'] );
		$exclude_ids  = array(); // Initialize as empty array
		if ( isset( $assoc_args['exclude'] ) ) {
		    $exclude_ids = explode( ',', $assoc_args['exclude'] );
		}
		$after_cursor = isset( $assoc_args['next' ] ) ? $assoc_args['next'] : null; // Use 'next' for cursor
		$processed_count = 0;

		// Build GraphQL query filter string
		$query_parts = array();
		$target_rest_ids = null; // Initialize to null
		if ( isset( $assoc_args['status'] ) ) {
			$query_parts[] = 'status:' . strtoupper( $assoc_args['status'] ); // GraphQL uses uppercase status
		}
		if ( isset( $assoc_args['ids'] ) ) {
			// Assuming IDs are Shopify REST IDs. Need to convert to GraphQL GIDs or query differently.
			// For now, we handle this by skipping if ID doesn't match.
			// A better approach would be a bulk `nodes(ids: [...])` query if possible.
			$target_rest_ids = explode(',', $assoc_args['ids']);
		}
		if ( isset( $assoc_args['handle'] ) ) {
			$query_parts[] = 'handle:' . $assoc_args['handle'];
		}
		if ( isset( $assoc_args['product-type'] ) && 'all' !== $assoc_args['product-type'] ) {
			$query_parts[] = 'product_type:"' . $assoc_args['product-type'] . '"'; // Quote if it contains spaces
		}
		if ( isset( $assoc_args['before'] ) ) {
			// Assuming ISO 8601 format
			$query_parts[] = 'created_at:<=' . $assoc_args['before'];
		}
		if ( isset( $assoc_args['after'] ) ) {
			// Assuming ISO 8601 format
			$query_parts[] = 'created_at:>=' . $assoc_args['after'];
		}
		$query_filter = implode( ' AND ', $query_parts );

		WP_CLI::line( 'Starting product migration using GraphQL...' );
		if ( $query_filter ) {
			WP_CLI::line( 'Using query filter: ' . $query_filter );
		}

		do {
			$batch_limit = min( $perpage, $limit - $processed_count );
			if ( $batch_limit <= 0 ) {
				break;
			}

			WP_CLI::line( sprintf( 'Fetching next %d products%s...', $batch_limit, $after_cursor ? ' after cursor ' . $after_cursor : '' ) );

			$variables = array(
				'first' => $batch_limit,
				'after' => $after_cursor,
				'query' => $query_filter,
			);

			$response_data = Migrator_CLI_Utils::graphql_request( self::SHOPIFY_PRODUCT_QUERY, $variables );

			if ( is_wp_error( $response_data ) || ! isset( $response_data->products ) ) {
				WP_CLI::error( 'Failed to fetch products via GraphQL. ' . ( is_wp_error( $response_data ) ? $response_data->get_error_message() : 'Invalid response structure.' ) );
				break;
			}

			$products = $response_data->products->edges;
			$pageInfo = $response_data->products->pageInfo;

			if ( empty( $products ) ) {
				WP_CLI::line( 'No more products found.' );
				break;
			}

			WP_CLI::line( sprintf( 'Fetched %d products.', count( $products ) ) );

			foreach ( $products as $edge ) {
				$shopify_product = $edge->node;
				$after_cursor    = $edge->cursor; // Update cursor for the next batch

				// Extract REST ID from GraphQL ID (e.g., gid://shopify/Product/12345 -> 12345)
				$rest_id = basename( $shopify_product->id );

				// Handle --ids filter (based on REST ID for now)
				if ( isset( $target_rest_ids ) && ! in_array( $rest_id, $target_rest_ids ) ) {
					WP_CLI::line( sprintf( 'Skipping product %s (Rest ID: %s) - Not in target IDs.', $shopify_product->handle, $rest_id ) );
					continue;
				}

				// Handle --exclude filter (based on REST ID)
				if ( in_array( $rest_id, $exclude_ids ) ) {
					WP_CLI::line( sprintf( 'Skipping product %s (Rest ID: %s) - Excluded.', $shopify_product->handle, $rest_id ) );
					continue;
				}

				WP_CLI::line( sprintf( 'Processing product %s (Rest ID: %s)...', $shopify_product->handle, $rest_id ) );

				// Check if product exists
				$woo_product = $this->get_corresponding_woo_product( $shopify_product );

				if ( $woo_product && $no_update ) {
					WP_CLI::line( sprintf( 'Skipping product %s (ID: %s) - Product already exists and --no-update flag is set.', $shopify_product->handle, $woo_product->get_id() ) );
				} else {
					try {
						// $this->fetch_additional_shopify_product_data( $shopify_product ); // No longer needed
						$this->create_or_update_woo_product( $shopify_product, $woo_product );
					} catch ( Exception $e ) {
						WP_CLI::warning( sprintf( 'Failed processing product %s (Rest ID: %s). Error: %s', $shopify_product->handle, $rest_id, $e->getMessage() ) );
					}
				}

				$processed_count++;
				if ( $processed_count >= $limit ) {
					WP_CLI::line( 'Reached processing limit (' . $limit . ').' );
					break 2; // Break outer loop
				}

				Migrator_CLI_Utils::maybe_stop_the_insanity();
			}

		} while ( $pageInfo->hasNextPage && $processed_count < $limit );

		WP_CLI::success( sprintf( 'Finished migrating products. Processed: %d', $processed_count ) );
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
	}

	/**
	 * Checks if the product contains variants.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return bool
	 */
	private function is_variable_product( $shopify_product ) {
		// Check the number of variant edges
		return count( $shopify_product->variants->edges ) > 1;
	}

	/**
	 * Gets the Woo product that matches the Shopify product id.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return WC_Product|null
	 */
	private function get_corresponding_woo_product( $shopify_product ) {
		// Try finding the product by original Shopify product ID.
		// Extract the REST ID from the GraphQL ID (e.g., gid://shopify/Product/12345 -> 12345)
		$rest_id = basename( $shopify_product->id );

		$woo_products = wc_get_products(
			array(
				'limit'      => 1,
				'meta_key'   => '_original_product_id',
				'meta_value' => $rest_id, // Use extracted REST ID for lookup
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
		$rest_id = basename( $shopify_product->id ); // Get REST ID for meta keys

		$this->migration_data = array(
			'product_id'         => $rest_id, // Store REST ID
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
			$product->set_description( $this->sanitize_product_description( $shopify_product->bodyHtml ) ); // Use bodyHtml
		}

		if ( $this->should_process( 'status' ) ) {
			$product->set_status( $this->get_woo_product_status( $shopify_product ) );
		}

		if ( $this->should_process( 'date_created' ) ) {
			$product->set_date_created( $shopify_product->createdAt ); // Use createdAt
		}

		if ( $this->should_process( 'catalog_visibility' ) ) {
			// Use onlineStoreUrl directly from the $shopify_product object
			if ( property_exists( $shopify_product, 'onlineStoreUrl' ) ) {
				if ( null === $shopify_product->onlineStoreUrl ) {
					$product->set_catalog_visibility( 'hidden' );
				} else {
					$this->migration_data['original_url'] = $shopify_product->onlineStoreUrl;
				}
			}
		}

		if ( $this->should_process( 'category' ) ) {
			// Pass the shopify_product object containing collections
			$product->set_category_ids( $this->get_woo_product_category_ids( $shopify_product ) );
		}

		if ( $this->should_process( 'tag' ) ) {
			$product->set_tag_ids( $this->get_woo_product_tag_ids( $shopify_product ) );
		}

		// Simple product.
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
				$product->set_manage_stock( 'SHOPIFY' === $variant_node->inventoryManagement ); // Check GraphQL enum
				$product->set_stock_status( $variant_node->inventoryQuantity <= 0 && 'DENY' === $variant_node->inventoryPolicy ? 'outofstock' : 'instock' ); // Check GraphQL policy
				$product->set_stock_quantity( $variant_node->inventoryQuantity );
			}
			if ( $this->should_process( 'weight' ) ) {
				$product->set_weight( $this->get_converted_weight( $variant_node->weight, $variant_node->weightUnit ) );
			}
			$variant_rest_id = basename( $variant_node->id ); // Get REST ID for meta
			$product->update_meta_data( '_original_variant_id', $variant_rest_id );
		} else {
			// For variable products, SKU might be empty at the product level
			$product->set_sku( '' );
		}

		// The operations below require product id, so we need to save the
		// product first.
		$product->save();

		// Product brand
		if ( $this->should_process( 'brand' ) ) {
			$this->set_woo_product_brand( $shopify_product, $product );
		}

		// Process images.
		if ( $this->should_process( 'images' ) ) {
			$this->upload_images( $shopify_product, $product );
			$product->set_image_id( $this->get_woo_product_image_id( $shopify_product ) );
			$product->set_gallery_image_ids( $this->get_woo_product_gallery_image_ids( $shopify_product ) );
		}

		$product->save(); // Save again after images/brand

		// Variations.
		if ( $this->is_variable_product( $shopify_product ) ) {
			$this->create_or_update_woo_product_variations( $shopify_product, $product );
		}

		if ( $this->should_process( 'seo' ) ) {
			$this->update_seo_title_description( $shopify_product, $product );
		}

		// Migration metas - from GraphQL metafields connection
		if ( property_exists( $shopify_product, 'metafields' ) && ! empty( $shopify_product->metafields->edges ) ) {
			foreach ( $shopify_product->metafields->edges as $edge ) {
				$field_node = $edge->node;
				$key = sprintf( '%s_%s', $field_node->namespace, $field_node->key );
				$this->migration_data['metafields'][ $key ] = $field_node->value;
			}
		}

		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->update_meta_data( '_original_product_id', $rest_id ); // Use REST ID

		$product->save();

		WP_CLI::line( 'Woo Product ID: ' . $product->get_id() );
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
		// Use htmlspecialchars to handle HTML entities instead of mb_convert_encoding
		$html = htmlspecialchars_decode(htmlspecialchars($html, ENT_QUOTES, 'UTF-8', false), ENT_QUOTES);

		if ( ! $html ) {
			return '';
		}

		
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

		// GraphQL status is uppercase (e.g., ACTIVE)
		if ( 'ACTIVE' === $shopify_product->status ) {
			$woo_product_status = 'publish';
		}

		return $woo_product_status;
	}

	/**
	 * Gets the Woo product category ids that match the collection handle in
	 * $shopify_product->collections->edges[collection]->node->handle
	 *
	 * @param object $shopify_product The Shopify product data from GraphQL.
	 * @return array
	 */
	private function get_woo_product_category_ids( $shopify_product ) {
		$category_ids = array();
		
		// Read collections from the GraphQL object
		if ( ! property_exists( $shopify_product, 'collections' ) || empty( $shopify_product->collections->edges ) ) {
			$category_ids[] = get_option( 'default_product_cat' );
			return $category_ids;
		}
		
		$collections  = $shopify_product->collections->edges;

		foreach ( $collections as $collection_edge ) {
			$collection_node = $collection_edge->node;
			// Check if the category exists in WooCommerce.
			$woo_product_category = get_term_by( 'slug', $collection_node->handle, 'product_cat', ARRAY_A );

			// If the category doesn't exist, create it.
			if ( ! $woo_product_category ) {
				$woo_product_category = wp_insert_term(
					$collection_node->title,
					'product_cat',
					array(
						'slug' => $collection_node->handle,
					)
				);
				if ( is_wp_error( $woo_product_category ) ) {
					WP_CLI::warning( "Failed to create category '{$collection_node->title}': " . $woo_product_category->get_error_message() );
					continue;
				}
			}

			$category_ids[] = $woo_product_category['term_id'];
		}

		if ( empty( $category_ids ) ) {
			$category_ids[] = get_option( 'default_product_cat' );
		}

		return $category_ids;
	}

	/**
	 * Gets the Woo product tags ids that match the Shopify product tags.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array
	 */
	private function get_woo_product_tag_ids( $shopify_product ) {
		$tag_ids = array();

		// Tags are directly available as an array in GraphQL response
		if ( empty( $shopify_product->tags ) ) {
			return $tag_ids;
		}

		$tags = $shopify_product->tags; // Already an array

		foreach ( $tags as $tag ) {
			$trimmed_tag = trim( $tag );
			if ( empty( $trimmed_tag ) ) continue;

			// Check if the tag exists in WooCommerce.
			$woo_product_tag = get_term_by( 'name', $trimmed_tag, 'product_tag', ARRAY_A ); // Check by name first

			// If the tag doesn't exist, create it.
			if ( ! $woo_product_tag ) {
				$tag_slug = sanitize_title( $trimmed_tag );
				$woo_product_tag = wp_insert_term(
					$trimmed_tag,
					'product_tag',
					array(
						'slug' => $tag_slug,
					)
				);
				if ( is_wp_error( $woo_product_tag ) ) {
					WP_CLI::warning( "Failed to create tag '{$trimmed_tag}': " . $woo_product_tag->get_error_message() );
					continue;
				}
			}

			$tag_ids[] = $woo_product_tag['term_id'];
		}

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

		// Use vendor field from GraphQL object
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
			if ( is_wp_error( $woo_product_brand ) ) {
				WP_CLI::warning( "Failed to create brand '{$brand}': " . $woo_product_brand->get_error_message() );
				return;
			}
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
		// Use images connection from GraphQL object
		if ( ! property_exists( $shopify_product, 'images' ) || empty( $shopify_product->images->edges ) ) {
			return;
		}

		// Clear existing mapping for this run unless it's already populated (e.g., from a previous partial run for this product)
		if ( empty( $this->migration_data['images_mapping'] ) ) {
		    $this->migration_data['images_mapping'] = array();
		}

		foreach ( $shopify_product->images->edges as $image_edge ) {
			$image_node = $image_edge->node;
			$image_gql_id = $image_node->id; // GraphQL ID of the image

			// Check if the image has already been uploaded and mapped in this session or a previous one.
			if ( isset( $this->migration_data['images_mapping'][ $image_gql_id ] ) && wp_attachment_is_image( $this->migration_data['images_mapping'][ $image_gql_id ] ) ) {
				WP_CLI::line( sprintf( 'Image %s already mapped to attachment ID %s. Skipping upload.', $image_gql_id, $this->migration_data['images_mapping'][ $image_gql_id ] ) );
				continue;
			}

			// Upload the image to the media library.
			WP_CLI::line( sprintf( 'Uploading image %s from %s...', $image_gql_id, $image_node->url ) );
			$image_id = media_sideload_image( $image_node->url, $product->get_id(), $image_node->altText, 'id' );

			if ( is_wp_error( $image_id ) ) {
				WP_CLI::warning( sprintf( 'Error uploading %s: %s', $image_node->url, $image_id->get_error_message() ) );
				continue; // Skip mapping if upload failed
			}

			// Save the mapping using GraphQL ID as key.
			$this->migration_data['images_mapping'][ $image_gql_id ] = $image_id;
			WP_CLI::line( sprintf( 'Mapped image %s to attachment ID %s.', $image_gql_id, $image_id ) );
		}
		
		// Update the migration data meta on the product immediately after processing images
		$product->update_meta_data( '_migration_data', $this->migration_data );
		// $product->save(); // Avoid saving here, will be saved later
	}

	/**
	 * Gets the Woo product image id that matches the first Shopify product image id.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return int
	 */
	private function get_woo_product_image_id( $shopify_product ) {
		// Use featuredImage from GraphQL object
		if ( empty( $shopify_product->featuredImage ) || empty( $this->migration_data['images_mapping'] ) ) {
			return 0;
		}

		$featured_image_gql_id = $shopify_product->featuredImage->id;

		// Get the WP attachment ID from mapping using GraphQL ID.
		return isset( $this->migration_data['images_mapping'][ $featured_image_gql_id ] ) ? $this->migration_data['images_mapping'][ $featured_image_gql_id ] : 0;
	}

	/**
	 * Gets the Woo product gallery image ids
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array
	 */
	private function get_woo_product_gallery_image_ids( $shopify_product ) {
		$gallery_ids = array();
		$featured_image_wp_id = $this->get_woo_product_image_id( $shopify_product );

		if ( empty( $this->migration_data['images_mapping'] ) ) {
			return $gallery_ids;
		}

		// All mapped WP attachment IDs are potential gallery images
		$all_wp_image_ids = array_values( $this->migration_data['images_mapping'] );

		// Exclude the featured image ID if it exists
		if ( $featured_image_wp_id ) {
			$gallery_ids = array_diff( $all_wp_image_ids, array( $featured_image_wp_id ) );
		} else {
			$gallery_ids = $all_wp_image_ids;
		}

		return array_values( $gallery_ids ); // Re-index array
	}

	/**
	 * Creates or updates Woo product variations.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @param WC_Product $product the Woo product.
	 */
	private function create_or_update_woo_product_variations( $shopify_product, $product ) {
		$attribute_taxonomy_mapping = array();
		$woo_attributes = array(); // Store WC_Product_Attribute objects

		if ( $this->should_process( 'attributes' ) && property_exists( $shopify_product, 'options' ) && ! empty( $shopify_product->options ) ) {
			// Create attribute taxonomies if needed based on GraphQL options.
			foreach ( $shopify_product->options as $option ) {
				$taxonomy_slug = sanitize_title( $option->name );
				$taxonomy_name = 'pa_' . $taxonomy_slug;

				// Check if the attribute taxonomy exists in WooCommerce.
				if ( ! taxonomy_exists( $taxonomy_name ) ) {
					$attribute_id = wc_create_attribute(
						array(
							'name'     => $option->name,
							'slug'     => $taxonomy_slug,
							'type'     => 'select',
							'order_by' => 'menu_order',
						)
					);
					if ( is_wp_error( $attribute_id ) ) {
						WP_CLI::warning("Failed to create attribute '{$option->name}': " . $attribute_id->get_error_message());
						continue;
					}
					WP_CLI::line( "Created attribute taxonomy: {$taxonomy_name}" );
				} else {
					$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy_name );
				}

				$attribute_taxonomy_mapping[ $option->name ] = $taxonomy_name; // Map Shopify option name to WC taxonomy name

				// Create terms for this attribute based on option->values
				$term_ids = array();
				foreach ( $option->values as $value ) {
					$term_slug = sanitize_title( $value );
					$term = get_term_by( 'slug', $term_slug, $taxonomy_name, ARRAY_A );
					if ( ! $term ) {
						$term_result = wp_insert_term(
							$value,
							$taxonomy_name,
							array( 'slug' => $term_slug )
						);
						if ( is_wp_error( $term_result ) ) {
							WP_CLI::warning("Failed to create term '{$value}' in '{$taxonomy_name}': " . $term_result->get_error_message());
							continue;
						}
						$term_ids[] = $term_result['term_id'];
					} else {
						$term_ids[] = $term['term_id'];
					}
				}

				// Create WC_Product_Attribute object
				$woo_attribute = new WC_Product_Attribute();
				$woo_attribute->set_name( $taxonomy_name );
				$woo_attribute->set_id( $attribute_id );
				$woo_attribute->set_options( $term_ids ); // Use term IDs
				$woo_attribute->set_position( $option->position );
				$woo_attribute->set_visible( true );
				$woo_attribute->set_variation( true );
				$woo_attributes[] = $woo_attribute;
			}

			// Force update the taxonomies registration (might still be needed).
			unregister_taxonomy( 'product_type' );
			WC_Post_Types::register_taxonomies();

			$product->set_attributes( $woo_attributes );
			$product->save();
			WP_CLI::line( 'Set product attributes.' );
		}

		if ( ! property_exists( $shopify_product, 'variants' ) || empty( $shopify_product->variants->edges ) ) {
			WP_CLI::warning( 'Product has no variants to process.' );
			return;
		}

		// Clear existing mapping for this run unless populated
		if ( empty( $this->migration_data['variations_mapping'] ) ) {
		    $this->migration_data['variations_mapping'] = array();
		}
		$processed_variation_ids = array(); // Keep track of variations processed in this run

		foreach ( $shopify_product->variants->edges as $variant_edge ) {
			$variant_node = $variant_edge->node;
			$variant_gql_id = $variant_node->id;
			$variant_rest_id = basename( $variant_gql_id );

			WP_CLI::line( 'Processing variant ' . $variant_gql_id . ' (Rest ID: ' . $variant_rest_id . ')' );
			$variation = null;

			// Check if the variant has been handled by our migrator before (using GQL ID as key).
			if ( isset( $this->migration_data['variations_mapping'][ $variant_gql_id ] ) ) {
				$_variation = wc_get_product( $this->migration_data['variations_mapping'][ $variant_gql_id ] );
				if ( is_a( $_variation, 'WC_Product_Variation' ) ) {
					$variation = $_variation;
					WP_CLI::line( 'Found existing variation (ID: ' . $variation->get_id() . ') via migration data. Updating.' );
				}
			} else {
				// Fallback check using the _original_variant_id meta key (using REST ID)
				$check_variation_args = array(
					'post_parent' => $product->get_id(),
					'post_type'   => 'product_variation',
					'numberposts' => 1,
					'meta_key'    => '_original_variant_id',
					'meta_value'  => $variant_rest_id,
				);
				$found_variations = get_posts( $check_variation_args );

				if ( ! empty( $found_variations ) ) {
					$variation = new WC_Product_Variation( $found_variations[0]->ID );
					WP_CLI::line( 'Found existing variation (ID: ' . $variation->get_id() . ') via meta query. Updating.' );
					// Store mapping using GQL ID for future runs
					$this->migration_data['variations_mapping'][ $variant_gql_id ] = $variation->get_id();
				}
			}

			// If not found, create a new one
			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
				WP_CLI::line( 'Creating new variation.' );
			}

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
					// If variant image wasn't uploaded during main image processing (e.g., not in product.images), try uploading now.
					WP_CLI::line( sprintf( 'Variant image %s not found in mapping. Attempting direct upload...', $variant_image_gql_id ) );
					$image_id = media_sideload_image( $variant_node->image->url, $product->get_id(), $variant_node->image->altText, 'id' );
					if ( ! is_wp_error( $image_id ) ) {
						$variation->set_image_id( $image_id );
						$this->migration_data['images_mapping'][ $variant_image_gql_id ] = $image_id; // Add to mapping
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
						// We need the term *slug* for variation attributes
						$term = get_term_by( 'name', $selectedOption->value, $taxonomy );
						if ( $term ) {
							$variation_attributes[ $taxonomy ] = $term->slug;
						} else {
							WP_CLI::warning( "Could not find term '{$selectedOption->value}' in taxonomy '{$taxonomy}' for variation {$variant_gql_id}."
							);
						}
					} else {
						WP_CLI::warning("Attribute taxonomy mapping not found for option '{$selectedOption->name}'.");
					}
				}
				$variation->set_attributes( $variation_attributes );
			}

			// Save the variant ID to the variation meta data.
			$variation->update_meta_data( '_original_variant_id', $variant_rest_id );
			$variation->update_meta_data( '_original_product_id', basename( $variant_node->product->id ) ); // Store product REST ID

			$variation_id = $variation->save();
			$processed_variation_ids[] = $variation_id;

			// Update mapping with GQL ID as key
			$this->migration_data['variations_mapping'][ $variant_gql_id ] = $variation_id;
		}

		// Update product meta with latest mappings
		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->save();

		$this->clean_up_orphan_variations( $product, $processed_variation_ids ); // Pass processed IDs
	}

	/**
	 * Updates the SEO tittle description for a product.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @param WC_Product $product the Woo product.
	 */
	private function update_seo_title_description( $shopify_product, WC_Product $product ) {
		// Check if Yoast is active
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		$current_seo_title       = $product->get_meta( '_yoast_wpseo_title' );
		$current_seo_description = $product->get_meta( '_yoast_wpseo_metadesc' );

		$title       = $product->get_name(); // Use get_name() which was set from shopify_product->title
		$description = $product->get_description() ? $product->get_description() : wp_strip_all_tags( get_the_excerpt( $product->get_id() ) ); // Use get_description() set from bodyHtml

		// Read metafields from GraphQL object
		if ( property_exists( $shopify_product, 'metafields' ) && ! empty( $shopify_product->metafields->edges ) ) {
			foreach ( $shopify_product->metafields->edges as $edge ) {
				$field_node = $edge->node;
				if ( 'global' === $field_node->namespace && 'title_tag' === $field_node->key && ! empty( $field_node->value ) ) {
					$title = $field_node->value;
				}

				if ( 'global' === $field_node->namespace && 'description_tag' === $field_node->key && ! empty( $field_node->value ) ) {
					$description = $field_node->value;
				}
			}
		}

		if ( $current_seo_title !== $title ) {
			$product->update_meta_data( '_yoast_wpseo_title', $title );
		}

		if ( $current_seo_description !== $description ) {
			$product->update_meta_data( '_yoast_wpseo_metadesc', $description );
		}

		// No need to save here, will be saved after this function returns
		// $product->save(); 
	}

	/**
	 * Removes variations that were not added/updated during this run.
	 *
	 * @param WC_Product $product the Woo product.
	 * @param array $processed_variation_ids WP variation IDs processed in the current run.
	 */
	private function clean_up_orphan_variations( $product, $processed_variation_ids ) {
		if ( ! isset( $this->assoc_args['remove-orphans'] ) ) {
			return;
		}

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
}
