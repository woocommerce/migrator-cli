<?php
/**
 * Core WooCommerce Importer Class.
 *
 * Responsible for taking standardized data (prepared by a Platform Mapper)
 * and creating/updating WooCommerce objects (products, orders, etc.).
 */
class WooCommerce_Product_Importer {

	private $migration_data; // Will store mappings (images, variations) internally
	private $processed_items; // Counter or log for summary
	private $skipped_items;   // Counter or log for summary
	private $verbose;         // Verbose logging flag

	/**
	 * Constructor.
	 *
	 * @param array $args Optional arguments (e.g., ['verbose' => true]).
	 */
	public function __construct( array $args = [] ) {
		$this->migration_data = [
			'images_mapping' => [],
			'variations_mapping' => [], // Keyed by original variation ID
			// Add other mappings as needed (e.g., categories, attributes?)
		];
		$this->processed_items = 0;
		$this->skipped_items   = 0;
		$this->verbose         = $args['verbose'] ?? false;

		WP_CLI::line( 'WooCommerce Importer initialized.' );
	}

	/**
	 * Main entry point to import a single product using standardized data.
	 *
	 * @param array $wc_data Standardized product data array from a Platform Mapper.
	 * @return int|WP_Error The WC Product ID on success, WP_Error on failure.
	 */
	public function import_product( array $wc_data ): int|WP_Error {
		try {
			$original_product_id = $wc_data['original_product_id'] ?? null;
			if ( ! $original_product_id ) {
				return new WP_Error( 'missing_original_id', 'Standardized data is missing the original_product_id.' );
			}

			// 1. Find existing WC product
			$product_id = $this->find_existing_product_id( $wc_data );
			$product = $product_id ? wc_get_product( $product_id ) : null;

			// Retrieve existing migration data if updating
			if ($product) {
				$existing_migration_data = $product->get_meta('_migration_data');
				if (is_array($existing_migration_data)) {
					$this->migration_data['images_mapping'] = $existing_migration_data['images_mapping'] ?? [];
					$this->migration_data['variations_mapping'] = $existing_migration_data['variations_mapping'] ?? [];
				}
			}

			// 2. Determine product type & Instantiate/Get WC_Product object
			$is_variable = $wc_data['is_variable'] ?? false;
			if ( $is_variable ) {
				if ( ! $product ) {
					$product = new WC_Product_Variable();
				} elseif ( ! $product->is_type('variable') ) {
					// Type mismatch - handle? For now, let's try converting it.
					WP_CLI::warning( sprintf('Product ID %d exists but is not variable. Attempting to convert.', $product_id) );
					$product = new WC_Product_Variable( $product_id );
				}
			} else {
				if ( ! $product ) {
					$product = new WC_Product_Simple();
				} elseif ( ! $product->is_type('simple') ) {
					// Type mismatch - handle? For now, let's try converting it.
					WP_CLI::warning( sprintf('Product ID %d exists but is not simple. Attempting to convert.', $product_id) );
					$product = new WC_Product_Simple( $product_id );
				}
			}

			// --- Set Common Properties --- 
			$product->set_name( $wc_data['name'] ?? '' );
			$product->set_slug( $wc_data['slug'] ?? '' );
			$product->set_description( $wc_data['description'] ?? '' );
			$product->set_status( $wc_data['status'] ?? 'draft' );
			if ( ! empty( $wc_data['date_created_gmt'] ) ) {
				$product->set_date_created( $wc_data['date_created_gmt'] );
			}
			$product->set_catalog_visibility( $wc_data['catalog_visibility'] ?? 'visible' );

			// --- Set Taxonomies --- (Helper needed)
			$this->set_taxonomies( $product, $wc_data ); // Pass full $wc_data for now

			// --- Handle Images --- (Helper needed)
			$this->handle_images( $product, $wc_data['images'] ?? [] );

			// --- Type-Specific Properties & Logic ---
			if ( $is_variable ) {
				// Ensure variable products don't have these set directly
				$product->set_sku('');
				$product->set_regular_price('');
				$product->set_sale_price('');
				$product->set_manage_stock(false);
				$product->set_weight('');
				$product->set_stock_quantity(null);

				// Setup attributes (Helper needed)
				$this->setup_attributes( $product, $wc_data['attributes'] ?? [] );

				// Sync variations (Helper needed)
				$this->sync_variations( $product, $wc_data['variations'] ?? [] );

			} else { // Simple Product
				$product->set_regular_price( $wc_data['regular_price'] ?? '' );
				$product->set_sale_price( $wc_data['sale_price'] ?? '' );
				// SKU - Allow filter to disable uniqueness check
				add_filter( 'wc_product_has_unique_sku', '__return_false', 999 );
				$product->set_sku( $wc_data['sku'] ?? '' );
				remove_filter( 'wc_product_has_unique_sku', '__return_false', 999 );

				$product->set_manage_stock( $wc_data['manage_stock'] ?? false );
				$product->set_stock_status( $wc_data['stock_status'] ?? 'instock' );
				$product->set_stock_quantity( $wc_data['stock_quantity'] ?? null );
				$product->set_weight( $wc_data['weight'] ?? '' ); // Assumes mapper provided converted weight

				// Store original simple variant ID if available
				if ( ! empty( $wc_data['original_variant_id'] ) ) {
					$product->update_meta_data( '_original_variant_id', $wc_data['original_variant_id'] );
				}
			}

			// --- Handle Metafields --- (Helper needed)
			$this->update_meta( $product, $wc_data ); // Pass full $wc_data for now

			// --- Final Save ---
			$new_product_id = $product->save();

			if ( $new_product_id ) {
				$this->processed_items++;
				return $new_product_id;
			} else {
				return new WP_Error( 'product_save_failed', 'Failed to save product.', ['original_id' => $original_product_id] );
			}

		} catch ( Exception $e ) {
			return new WP_Error(
				'importer_exception',
				'Exception during product import: ' . $e->getMessage(),
				['original_id' => $wc_data['original_product_id'] ?? 'unknown']
			);
		}
	}

	/**
	 * Get the import summary.
	 *
	 * @return array Summary data (processed, skipped counts).
	 */
	public function get_summary(): array {
		return [
			'processed' => $this->processed_items,
			'skipped' => $this->skipped_items,
		];
	}

	/**
	 * Finds an existing WooCommerce product ID based on the original product ID meta.
	 *
	 * @param array $wc_data Standardized data containing ['original_product_id'].
	 * @return ?int WC Product ID if found, null otherwise.
	 */
	public function find_existing_product_id( array $wc_data ): ?int {
		if ( empty( $wc_data['original_product_id'] ) ) {
			return null;
		}

		$original_product_id = $wc_data['original_product_id'];

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'any', // Find regardless of status
			'meta_key'       => '_original_product_id',
			'meta_value'     => $original_product_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		);

		$found_ids = get_posts( $query_args );

		return ! empty( $found_ids ) ? (int) $found_ids[0] : null;
	}

	/**
	 * Sets product taxonomies (categories, tags, brand).
	 * Creates terms if they don't exist.
	 *
	 * @param WC_Product $product The product object.
	 * @param array      $wc_data Standardized data containing ['categories'], ['tags'], ['brand'].
	 */
	private function set_taxonomies( WC_Product $product, array $wc_data ): void {
		$taxonomies_to_set = [];

		// Categories
		if ( isset( $wc_data['categories'] ) && is_array( $wc_data['categories'] ) ) {
			$term_ids = $this->get_or_create_terms( $wc_data['categories'], 'product_cat' );
			if ( ! empty( $term_ids ) ) {
				$taxonomies_to_set['product_cat'] = $term_ids;
			} elseif ( $default_cat_id = get_option( 'default_product_cat' ) ) {
				$taxonomies_to_set['product_cat'] = [ $default_cat_id ];
			}
		}

		// Tags
		if ( isset( $wc_data['tags'] ) && is_array( $wc_data['tags'] ) ) {
			$term_ids = $this->get_or_create_terms( $wc_data['tags'], 'product_tag' );
			if ( ! empty( $term_ids ) ) {
				$taxonomies_to_set['product_tag'] = $term_ids;
			}
		}

		// Brand (assuming 'product_brand' taxonomy)
		if ( ! empty( $wc_data['brand']['name'] ) && taxonomy_exists('product_brand') ) {
			$brand_data = [$wc_data['brand']]; // Wrap in array for get_or_create_terms
			$term_ids = $this->get_or_create_terms( $brand_data, 'product_brand' );
			if ( ! empty( $term_ids ) ) {
				$taxonomies_to_set['product_brand'] = $term_ids;
			}
		}

		// Set all taxonomies at once
		foreach ( $taxonomies_to_set as $taxonomy => $ids ) {
			wp_set_object_terms( $product->get_id(), $ids, $taxonomy, false ); // false = replace terms
		}
	}

	/**
	 * Helper to get or create term IDs for a given taxonomy.
	 *
	 * @param array $terms_data Array of ['name' => ..., 'slug' => ...].
	 * @param string $taxonomy Taxonomy slug.
	 * @return array Array of term IDs.
	 */
	private function get_or_create_terms( array $terms_data, string $taxonomy ): array {
		$term_ids = [];
		foreach ( $terms_data as $term_info ) {
			$term_name = $term_info['name'] ?? null;
			$term_slug = $term_info['slug'] ?? sanitize_title( $term_name );

			if ( empty( $term_name ) || empty( $term_slug ) ) continue;

			$term = get_term_by( 'slug', $term_slug, $taxonomy );

			if ( ! $term ) {
				$term_result = wp_insert_term( $term_name, $taxonomy, [ 'slug' => $term_slug ] );
				if ( is_wp_error( $term_result ) ) {
					if ( $this->verbose ) WP_CLI::warning( sprintf( " - Failed to insert term '%s' (slug: %s) into %s: %s", $term_name, $term_slug, $taxonomy, $term_result->get_error_message() ) );
					continue;
				}
				$term_ids[] = $term_result['term_id'];
				if ( $this->verbose ) WP_CLI::line( sprintf( " - Created term '%s' (ID: %d) in %s", $term_name, $term_result['term_id'], $taxonomy ) );
			} else {
				$term_ids[] = $term->term_id;
			}
		}
		return array_unique( $term_ids );
	}

	/**
	 * Handles image downloading, attachment, mapping, and setting featured/gallery.
	 * Updates $this->migration_data['images_mapping'].
	 *
	 * @param WC_Product $product The product object.
	 * @param array      $images_data Standardized image data from mapper.
	 */
	private function handle_images( WC_Product $product, array $images_data ): void {
		if ( empty( $images_data ) ) return;

		if ( $this->verbose ) WP_CLI::line( ' - Starting image processing...' );

		$gallery_ids = [];
		$featured_id = null;
		$product_id = $product->get_id(); // Needed for media_sideload_image

		foreach ( $images_data as $img ) {
			$original_id = $img['original_id'] ?? null;
			$image_url = $img['url'] ?? null;
			$image_alt = $img['alt'] ?? null;
			$is_featured = $img['is_featured'] ?? false;

			if ( empty( $original_id ) || empty( $image_url ) ) {
				if ( $this->verbose ) WP_CLI::warning( ' - Skipping image: Missing original ID or URL.' );
				continue;
			}

			// Check if already mapped and valid
			if ( isset( $this->migration_data['images_mapping'][ $original_id ] ) && wp_attachment_is_image( $this->migration_data['images_mapping'][ $original_id ] ) ) {
				$attachment_id = $this->migration_data['images_mapping'][ $original_id ];
				if ( $this->verbose ) WP_CLI::line( sprintf( ' - Skipping image download %s: Already mapped to attachment ID %s.', $original_id, $attachment_id ) );
			} else {
				// Sideload image
				if ( $this->verbose ) WP_CLI::line( sprintf( ' - Uploading image %s from %s...', $original_id, $image_url ) );
				$image_desc = $image_alt ?: $product->get_name();

				// Ensure product ID exists before sideloading
				if (!$product_id) { 
					$product_id = $product->save();
					if (!$product_id) {
						WP_CLI::warning( sprintf( ' - Skipping image upload %s: Could not get product ID before sideloading.', $original_id ) );
						continue;
					}
				}

				$start_time = microtime(true);
				$attachment_id = media_sideload_image( $image_url, $product_id, $image_desc, 'id' );
				$duration = microtime(true) - $start_time;

				if ( is_wp_error( $attachment_id ) ) {
					WP_CLI::warning( sprintf( ' - Error uploading %s: %s (Duration: %.2fs)', $image_url, $attachment_id->get_error_message(), $duration ) );
					continue;
				}

				// Map original ID to WP attachment ID
				$this->migration_data['images_mapping'][ $original_id ] = $attachment_id;
				if ( $this->verbose ) WP_CLI::line( sprintf( ' - Mapped image %s to attachment ID %s. (Upload duration: %.2fs)', $original_id, $attachment_id, $duration ) );

				// Set alt text if provided
				if ( $image_alt ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $image_alt );
				}
			}

			// Add to gallery or set as featured
			if ( $is_featured ) {
				$featured_id = $attachment_id;
			} else {
				$gallery_ids[] = $attachment_id;
			}
		}

		// Set featured image and gallery
		$product->set_image_id( $featured_id ?? '' );
		$product->set_gallery_image_ids( array_unique( $gallery_ids ) );
		if ( $this->verbose ) WP_CLI::line( sprintf( ' - Set featured image: %s | Gallery IDs: %s', $featured_id ?? 'None', implode(',', $gallery_ids) ?: 'None' ) );
	}

	/**
	 * Sets up product attributes for variable products.
	 *
	 * @param WC_Product_Variable $product The variable product object.
	 * @param array $attributes_data Standardized attribute data from mapper.
	 */
	private function setup_attributes( WC_Product_Variable $product, array $attributes_data ): void {
		$woo_attributes = [];
		foreach ( $attributes_data as $attribute_info ) {
			$attr_name = $attribute_info['name'] ?? null;
			$attr_options = $attribute_info['options'] ?? [];
			if ( empty($attr_name) || empty($attr_options) ) continue;

			$taxonomy_slug = sanitize_title( $attr_name );
			$taxonomy_name = 'pa_' . $taxonomy_slug;
			$attribute_id = 0;

			// Get or create the global attribute taxonomy
			if ( ! taxonomy_exists( $taxonomy_name ) ) {
				$attribute_id = wc_create_attribute(
					array(
						'name'         => $attr_name,
						'slug'         => $taxonomy_slug,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					)
				);
				if ( is_wp_error( $attribute_id ) ) {
					WP_CLI::warning( " - Failed to create attribute '{$attr_name}': " . $attribute_id->get_error_message() );
					continue;
				}
				if ( $this->verbose ) WP_CLI::line( sprintf(" - Created attribute '%s' (Tax: %s, ID: %d)", $attr_name, $taxonomy_name, $attribute_id) );
			} else {
				$attribute_id = wc_attribute_taxonomy_id_by_name( $taxonomy_name );
			}

			// Get or create terms for the attribute
			$term_ids = [];
			$term_slugs = [];
			foreach ( $attr_options as $value ) {
				$term_slug = sanitize_title( $value );
				$term = get_term_by( 'slug', $term_slug, $taxonomy_name );
				if ( ! $term ) {
					$term_result = wp_insert_term( $value, $taxonomy_name, array( 'slug' => $term_slug ) );
					if ( is_wp_error( $term_result ) ) {
						WP_CLI::warning( " - Failed to insert term '{$value}' (slug: {$term_slug}) into {$taxonomy_name}: " . $term_result->get_error_message() );
						continue;
					}
					$term_ids[] = $term_result['term_id'];
					$term_slugs[] = $term_slug;
				} else {
					$term_ids[] = $term->term_id;
					$term_slugs[] = $term->slug;
				}
			}

			// Create WC_Product_Attribute object
			$woo_attribute = new WC_Product_Attribute();
			$woo_attribute->set_name( $taxonomy_name );
			$woo_attribute->set_id( $attribute_id );
			// Set options using term slugs for product attribute assignment
			$woo_attribute->set_options( $term_slugs ); 
			$woo_attribute->set_position( $attribute_info['position'] ?? 0 );
			$woo_attribute->set_visible( $attribute_info['is_visible'] ?? true );
			$woo_attribute->set_variation( $attribute_info['is_variation'] ?? true );
			$woo_attributes[] = $woo_attribute;
		}

		$product->set_attributes( $woo_attributes );
	}

	/**
	 * Creates or updates product variations.
	 * Updates $this->migration_data['variations_mapping'].
	 *
	 * @param WC_Product_Variable $product The parent variable product.
	 * @param array $variations_data Standardized variation data from mapper.
	 */
	private function sync_variations( WC_Product_Variable $product, array $variations_data ): void {
		$parent_product_id = $product->get_id();
		$parent_original_id = $product->get_meta('_original_product_id'); // Needed for meta link
		$processed_variation_ids = []; // Track WC variation IDs processed in this run

		// Build mapping of attribute label -> taxonomy slug from parent
		$attribute_taxonomy_map = [];
		foreach ( $product->get_attributes() as $taxonomy => $attribute_obj ) {
			if ( $attribute_obj->get_variation() ) {
				$attribute_label = wc_attribute_label( $taxonomy, $product );
				$attribute_taxonomy_map[ $attribute_label ] = $taxonomy;
			}
		}

		foreach ( $variations_data as $var_data ) {
			$original_variant_id = $var_data['original_id'] ?? null;
			if ( ! $original_variant_id ) {
				WP_CLI::warning(' - Skipping variation: Missing original ID.');
				continue;
			}

			$variation_id = null;
			$variation = null;

			// 1. Find existing variation (using mapping first, then meta)
			if ( isset( $this->migration_data['variations_mapping'][ $original_variant_id ] ) ) {
				$_variation_id = $this->migration_data['variations_mapping'][ $original_variant_id ];
				$_variation = wc_get_product( $_variation_id );
				if ( $_variation instanceof WC_Product_Variation && $_variation->get_parent_id() === $parent_product_id ) {
					$variation = $_variation;
					$variation_id = $_variation_id;
				} else {
					unset( $this->migration_data['variations_mapping'][ $original_variant_id ] ); // Clean invalid mapping
				}
			}

			if ( ! $variation ) {
				$query_args = array();
				$query_args['post_parent'] = $parent_product_id;
				$query_args['post_type']   = 'product_variation';
				$query_args['numberposts'] = 1;
				$query_args['post_status'] = 'any';
				$query_args['meta_key']    = '_original_variant_id';
				$query_args['meta_value']  = $original_variant_id;
				$query_args['fields']      = 'ids';

				$found_ids = get_posts( $query_args );
				if ( ! empty( $found_ids ) ) {
					$variation_id = $found_ids[0];
					$variation = wc_get_product( $variation_id );
					if ( ! ( $variation instanceof WC_Product_Variation ) ) {
						WP_CLI::warning( " - Found post ID {$variation_id} for original variant {$original_variant_id}, but it's not a WC_Product_Variation." );
						$variation = null; $variation_id = null;
					}
				}
			}

			// 2. Create new variation if not found
			if ( ! $variation ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $parent_product_id );
			}

			// 3. Set variation properties
			$variation->set_status( 'publish' );
			$variation->set_menu_order( $var_data['menu_order'] ?? 0 );

			$variation->set_regular_price( $var_data['regular_price'] ?? '' );
			$variation->set_sale_price( $var_data['sale_price'] ?? '' );

			add_filter( 'wc_product_has_unique_sku', '__return_false', 999 );
			$variation->set_sku( $var_data['sku'] ?? '' );
			remove_filter( 'wc_product_has_unique_sku', '__return_false', 999 );

			$variation->set_manage_stock( $var_data['manage_stock'] ?? false );
			$variation->set_stock_quantity( $var_data['stock_quantity'] ?? null );
			$variation->set_stock_status( $var_data['stock_status'] ?? 'instock' );

			$variation->set_weight( $var_data['weight'] ?? '' );

			// Set variation image using mapping
			$image_original_id = $var_data['image_original_id'] ?? null;
			if ( $image_original_id && isset( $this->migration_data['images_mapping'][ $image_original_id ] ) ) {
				$variation->set_image_id( $this->migration_data['images_mapping'][ $image_original_id ] );
			} else {
				$variation->set_image_id( '' );
				if ( $image_original_id && $this->verbose ) {
					WP_CLI::line( sprintf( ' - Warning: Image mapping not found for original image ID %s on variation %s.', $image_original_id, $original_variant_id ) );
				}
			}

			// 4. Set variation attributes
			$wc_variation_attributes = [];
			if ( ! empty( $var_data['attributes'] ) && is_array( $var_data['attributes'] ) ) {
				foreach ( $var_data['attributes'] as $attr_name => $attr_value ) {
					if ( isset( $attribute_taxonomy_map[ $attr_name ] ) ) {
						$taxonomy = $attribute_taxonomy_map[ $attr_name ];
						// Variation attributes are set using slugs
						$term_slug = sanitize_title( $attr_value ); 
						$wc_variation_attributes[ $taxonomy ] = $term_slug;
					} else {
						WP_CLI::warning( sprintf( ' - Attribute taxonomy mapping not found for option \'%s\' while processing variation %s.', $attr_name, $original_variant_id ) );
					}
				}
			}
			$variation->set_attributes( $wc_variation_attributes );

			// 5. Set meta
			$variation->update_meta_data( '_original_variant_id', $original_variant_id );
			if ($parent_original_id) $variation->update_meta_data( '_original_product_id', $parent_original_id );

			// 6. Save variation
			$saved_variation_id = $variation->save();
			if ( $saved_variation_id ) {
				$processed_variation_ids[] = $saved_variation_id;
				// Update mapping
				$this->migration_data['variations_mapping'][ $original_variant_id ] = $saved_variation_id;
				if ( $this->verbose ) WP_CLI::line( sprintf( ' - Saved variation ID %d for original variant %s', $saved_variation_id, $original_variant_id ) );
			} else {
				WP_CLI::warning( sprintf( ' - Failed to save variation for original variant %s', $original_variant_id ) );
			}
		}

		// 7. Clean up orphans if flag is set
		// $this->clean_up_orphan_variations( $product, $processed_variation_ids ); // TODO: Implement if needed

		// 8. Sync parent prices/stock status
		// WC_Product_Variable_Data_Store_CPT::sync_variation_prices( $parent_product_id );
		// wc_product_sync_stock_status( $parent_product_id );
	}

	/**
	 * Updates product meta, including migration data and potentially SEO.
	 *
	 * @param WC_Product $product The product object.
	 * @param array      $wc_data Standardized data containing ['metafields'], ['original_product_id'].
	 */
	private function update_meta( WC_Product $product, array $wc_data ): void {
		// Store original product ID
		if ( ! empty( $wc_data['original_product_id'] ) ) {
			$product->update_meta_data( '_original_product_id', $wc_data['original_product_id'] );
		}

		// Store migration data (image/variation mappings)
		$product->update_meta_data( '_migration_data', $this->migration_data );

		// Store original platform URL if available
		if ( ! empty( $wc_data['original_url'] ) ) {
			$product->update_meta_data( '_original_url', $wc_data['original_url'] );
		}

		// Handle generic metafields (example: Yoast SEO)
		if ( ! empty( $wc_data['metafields'] ) ) {
			$this->update_seo_meta( $product, $wc_data['metafields'] );
			// Add logic for other known metafields if needed
		}
	}

	/**
	 * Updates Yoast SEO meta fields if Yoast is active.
	 *
	 * @param WC_Product $product    The product object.
	 * @param array      $metafields Key-value array of metafields from standardized data.
	 */
	private function update_seo_meta( WC_Product $product, array $metafields ): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		// Assuming keys like 'global_title_tag' and 'global_description_tag' from Shopify mapper
		$seo_title       = $metafields['global_title_tag'] ?? null;
		$seo_description = $metafields['global_description_tag'] ?? null;

		$final_seo_title = $seo_title ?: $product->get_name();
		// Use description or short description as fallback for meta description
		$fallback_desc = $product->get_description() ?: $product->get_short_description();
		$final_seo_description = $seo_description ?: wp_strip_all_tags( $fallback_desc );

		// Only update if changed
		if ( $product->get_meta( '_yoast_wpseo_title' ) !== $final_seo_title ) {
			$product->update_meta_data( '_yoast_wpseo_title', $final_seo_title );
			if ( $this->verbose ) WP_CLI::line( " - Updating Yoast title to: {$final_seo_title}" );
		}
		if ( $product->get_meta( '_yoast_wpseo_metadesc' ) !== $final_seo_description ) {
			$product->update_meta_data( '_yoast_wpseo_metadesc', $final_seo_description );
			if ( $this->verbose ) WP_CLI::line( " - Updating Yoast description..." ); // Avoid printing potentially long desc
		}
	}
} 