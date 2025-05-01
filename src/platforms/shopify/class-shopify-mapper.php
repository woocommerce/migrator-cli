<?php

/**
 * Maps Shopify product data to a standardized WooCommerce format.
 */
class Shopify_Mapper implements Platform_Mapper_Interface {

	// Dependency needed for should_process - temporarily add placeholder
	// TODO: Refactor how field filtering is passed/handled later
	private $fields_to_process = []; 

	/**
	 * Constructor (optional, can be used to inject dependencies like field lists).
	 *
	 * @param array $args Optional arguments (e.g., ['fields' => ['title', 'price']]).
	 */
	public function __construct(array $args = []) {
		$this->fields_to_process = $args['fields'] ?? $this->get_default_product_fields();
	}

	/**
	 * Maps raw Shopify product data (GraphQL node) to a standardized array format.
	 *
	 * @param object $shopify_product The raw Shopify product node from GraphQL.
	 * @return array Standardized data array for WooCommerce_Product_Importer.
	 */
	public function map_product_data( object $shopify_product ): array {
		$wc_data = [];

		$is_variable = $this->is_variable_product( $shopify_product );
		$wc_data['is_variable'] = $is_variable;
		$wc_data['original_product_id'] = basename( $shopify_product->id ); // Add original ID to standard data

		// --- Basic Product Fields ---
		$wc_data['name']        = $shopify_product->title;
		$wc_data['slug']        = $shopify_product->handle;
		$wc_data['description'] = $this->sanitize_product_description( $shopify_product->descriptionHtml ?? '' );
		$wc_data['status']      = $this->get_woo_product_status( $shopify_product );
		$wc_data['date_created_gmt'] = $shopify_product->createdAt; // Assuming createdAt is in UTC/GMT

		// --- Catalog Visibility & Original URL ---
		$wc_data['catalog_visibility'] = 'visible'; // Default
		$wc_data['original_url'] = null;
		if ( property_exists( $shopify_product, 'onlineStoreUrl' ) ) {
			if ( null === $shopify_product->onlineStoreUrl ) {
				$wc_data['catalog_visibility'] = 'hidden';
			} else {
				$wc_data['original_url'] = $shopify_product->onlineStoreUrl;
			}
		}

		// --- Taxonomies --- Standardizing to names/slugs for importer
		$wc_data['categories'] = $this->get_mapped_categories( $shopify_product ); // Returns array of ['name' => ..., 'slug' => ...]
		$wc_data['tags']       = $this->get_mapped_tags( $shopify_product ); // Returns array of ['name' => ..., 'slug' => ...]

		// --- Brand (Vendor) --- Standardizing to name/slug
		$brand_name = $shopify_product->vendor ?? null;
		$wc_data['brand'] = $brand_name ? [ 'name' => $brand_name, 'slug' => sanitize_title( $brand_name ) ] : null;

		// --- Simple Product Fields ---
		if ( ! $is_variable && ! empty( $shopify_product->variants->edges ) ) {
			$variant_node = $shopify_product->variants->edges[0]->node;
			// Price
			if ( $this->should_process( 'price' ) ) {
				if ( $variant_node->compareAtPrice && $variant_node->compareAtPrice > $variant_node->price ) {
					$wc_data['sale_price']    = $variant_node->price;
					$wc_data['regular_price'] = $variant_node->compareAtPrice;
				} else {
					$wc_data['sale_price']    = null; // Explicitly null
					$wc_data['regular_price'] = $variant_node->price;
				}
			}
			// SKU
			if ( $this->should_process( 'sku' ) ) {
				$wc_data['sku'] = $variant_node->sku;
			}
			// Stock
			if ( $this->should_process( 'stock' ) ) {
				$manage_stock = property_exists( $variant_node, 'inventoryItem' ) && $variant_node->inventoryItem->tracked;
				$wc_data['manage_stock'] = $manage_stock;
				$stock_quantity = $variant_node->inventoryQuantity ?? 0;
				$allow_oversell = $manage_stock && 'CONTINUE' === $variant_node->inventoryPolicy;
				$wc_data['stock_status'] = ( $stock_quantity > 0 || $allow_oversell ) ? 'instock' : 'outofstock';
				$wc_data['stock_quantity'] = $stock_quantity;
			}
			// Weight
			if ( $this->should_process( 'weight' ) ) {
				$weight_data = null;
				if ( property_exists( $variant_node, 'inventoryItem' ) && is_object( $variant_node->inventoryItem ) &&
					 property_exists( $variant_node->inventoryItem, 'measurement' ) && is_object( $variant_node->inventoryItem->measurement ) &&
					 property_exists( $variant_node->inventoryItem->measurement, 'weight' ) && is_object( $variant_node->inventoryItem->measurement->weight )
				) {
					$weight_data = $variant_node->inventoryItem->measurement->weight;
				}
				$weight = $weight_data ? $weight_data->value : null;
				$weight_unit = $weight_data ? $weight_data->unit : null;
				$wc_data['weight'] = $this->get_converted_weight( $weight, $weight_unit ); // Store converted weight
			}

			$wc_data['original_variant_id'] = basename( $variant_node->id ); // Store original simple variant ID

		} else {
			// Defaults for variable or product with no variants
			$wc_data['sku'] = null;
			$wc_data['regular_price'] = null;
			$wc_data['sale_price'] = null;
			$wc_data['stock_quantity'] = null;
			$wc_data['manage_stock'] = false;
			$wc_data['stock_status'] = 'instock';
			$wc_data['weight'] = null;
			$wc_data['original_variant_id'] = null;
		}

		// --- Images --- Prepare image data structure for importer/sideloading
		$wc_data['images'] = [];
		$featured_media_id = null;
		if ( ! empty( $shopify_product->featuredMedia ) && is_object( $shopify_product->featuredMedia ) && ! empty( $shopify_product->featuredMedia->id ) ) {
			 $featured_media_id = $shopify_product->featuredMedia->id;
		}

		if ( ! empty( $shopify_product->media->edges ) ) {
			foreach ( $shopify_product->media->edges as $media_edge ) {
				$media_node = $media_edge->node;
				if ( property_exists( $media_node, 'image' ) && is_object( $media_node->image ) && ! empty( $media_node->id ) && ! empty( $media_node->image->url ) ) {
					$wc_data['images'][] = [
						'original_id' => $media_node->id, // MediaImage GQL ID
						'url'         => $media_node->image->url,
						'alt'         => $media_node->image->altText ?? null,
						'is_featured' => ( $media_node->id === $featured_media_id ),
					];
				}
			}
		}

		// --- Metafields --- Store in a simple key-value array
		$wc_data['metafields'] = [];
		if ( property_exists( $shopify_product, 'metafields' ) && ! empty( $shopify_product->metafields->edges ) ) {
			foreach ( $shopify_product->metafields->edges as $edge ) {
				$field_node = $edge->node;
				$key = sprintf( '%s_%s', $field_node->namespace, $field_node->key );
				$wc_data['metafields'][ $key ] = $field_node->value;
			}
		}

		// --- Attributes (Variable Only) --- Standardized format for Importer
		$wc_data['attributes'] = [];
		if ( $is_variable && property_exists( $shopify_product, 'options' ) && ! empty( $shopify_product->options ) ) {
			foreach ( $shopify_product->options as $option ) {
				$wc_data['attributes'][] = [
					'name'         => $option->name,
					'options'      => $option->values, // Array of string values (Importer handles term creation)
					'position'     => $option->position,
					'is_visible'   => true, // Default assumption
					'is_variation' => true, // Default assumption
				];
			}
		}

		// --- Variations (Variable Only) --- Standardized format for Importer
		$wc_data['variations'] = [];
		if ( $is_variable && property_exists( $shopify_product, 'variants' ) && ! empty( $shopify_product->variants->edges ) ) {
			foreach ( $shopify_product->variants->edges as $variant_edge ) {
				$variant_node = $variant_edge->node;
				$variation_data = [];
				$variation_data['original_id'] = basename( $variant_node->id );

				// Price
				if ( $this->should_process('price') ) {
					if ( $variant_node->compareAtPrice && (float) $variant_node->compareAtPrice > (float) $variant_node->price ) {
						$variation_data['regular_price'] = $variant_node->compareAtPrice;
						$variation_data['sale_price']    = $variant_node->price;
					} else {
						$variation_data['regular_price'] = $variant_node->price;
						$variation_data['sale_price']    = null;
					}
				}

				// SKU
				if ( $this->should_process('sku') ) {
					$variation_data['sku'] = $variant_node->sku ?? null;
				}

				// Stock
				if ( $this->should_process('stock') ) {
					$manage_stock = property_exists( $variant_node, 'inventoryItem' ) && $variant_node->inventoryItem->tracked;
					$variation_data['manage_stock'] = $manage_stock;
					$stock_quantity = $variant_node->inventoryQuantity ?? 0;
					$allow_oversell = $manage_stock && 'CONTINUE' === $variant_node->inventoryPolicy;
					$variation_data['stock_status'] = ( $stock_quantity > 0 || $allow_oversell ) ? 'instock' : 'outofstock';
					$variation_data['stock_quantity'] = $stock_quantity;
				}

				// Weight
				if ( $this->should_process('weight') ) {
					$weight_data = null;
					if ( property_exists( $variant_node, 'inventoryItem' ) && is_object( $variant_node->inventoryItem ) &&
						 property_exists( $variant_node->inventoryItem, 'measurement' ) && is_object( $variant_node->inventoryItem->measurement ) &&
						 property_exists( $variant_node->inventoryItem->measurement, 'weight' ) && is_object( $variant_node->inventoryItem->measurement->weight )
					) {
						$weight_data = $variant_node->inventoryItem->measurement->weight;
					}
					$weight = $weight_data ? $weight_data->value : null;
					$weight_unit = $weight_data ? $weight_data->unit : null;
					$variation_data['weight'] = $this->get_converted_weight( $weight, $weight_unit ); // Store converted weight
				}

				// Mapped Attributes (using option name as key)
				if ( $this->should_process('attributes') ) {
					$variation_data['attributes'] = [];
					if ( ! empty( $variant_node->selectedOptions ) ) {
						foreach ( $variant_node->selectedOptions as $selectedOption ) {
							// We use the option name as the key, value as the value
							$variation_data['attributes'][ $selectedOption->name ] = $selectedOption->value;
						}
					}
				}

				// Image Mapping (store original ID, importer will map to WC ID later)
				if ( $this->should_process('images') ) {
					$variation_data['image_original_id'] = null;
					if ( ! empty( $variant_node->media->edges ) ) {
						$variant_media_node = $variant_node->media->edges[0]->node ?? null;
						if ( $variant_media_node && property_exists( $variant_media_node, 'image' ) && is_object( $variant_media_node->image ) && ! empty( $variant_media_node->id ) ) {
							$variation_data['image_original_id'] = $variant_media_node->id;
						}
					}
				}

				// Menu Order / Position
				$variation_data['menu_order'] = $variant_node->position;

				$wc_data['variations'][] = $variation_data;
			}
		}

		return $wc_data;
	}


	/**
	 * Checks if a product is a variable product.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return bool true if the product is a variable product, false otherwise.
	 */
	private function is_variable_product( $shopify_product ) {
		// Assumes multiple variants means variable. Might need refinement for single-variant variable products.
		return isset( $shopify_product->variants->edges ) && count( $shopify_product->variants->edges ) > 1;
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
		// Add handling for ARCHIVED, DRAFT if necessary
		return $woo_product_status;
	}

	/**
	 * Gets mapped Woo product categories from Shopify collections.
	 * Returns an array of arrays containing name and slug.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array Mapped category data (e.g., [[ 'name' => 'T-Shirts', 'slug' => 't-shirts' ]]).
	 */
	private function get_mapped_categories( $shopify_product ): array {
		$categories = [];
		if ( ! property_exists( $shopify_product, 'collections' ) || empty( $shopify_product->collections->edges ) ) {
			// Optionally map to default category? For now, return empty.
			return $categories;
		}

		foreach ( $shopify_product->collections->edges as $collection_edge ) {
			$collection_node = $collection_edge->node;
			$categories[] = [
				'name' => $collection_node->title,
				'slug' => $collection_node->handle,
			];
		}

		return $categories;
	}

	/**
	 * Gets mapped Woo product tags from Shopify tags.
	 * Returns an array of arrays containing name and slug.
	 *
	 * @param object $shopify_product the Shopify product data.
	 * @return array Mapped tag data (e.g., [[ 'name' => 'Sale', 'slug' => 'sale' ]]).
	 */
	private function get_mapped_tags( $shopify_product ): array {
		$tags = [];
		if ( empty( $shopify_product->tags ) ) {
			return $tags;
		}

		foreach ( $shopify_product->tags as $tag ) {
			$trimmed_tag = trim( $tag );
			if ( ! empty( $trimmed_tag ) ) {
				$tags[] = [
					'name' => $trimmed_tag,
					'slug' => sanitize_title( $trimmed_tag ),
				];
			}
		}
		return $tags;
	}

	/**
	 * Converts weight based on Shopify weight unit to store's weight unit.
	 *
	 * @param ?float  $weight      The weight value from Shopify.
	 * @param ?string $weight_unit The weight unit from Shopify (e.g., GRAMS, KILOGRAMS).
	 * @return ?float The converted weight, or null if input is invalid/zero.
	 */
	private function get_converted_weight( $weight, $weight_unit ): ?float {
		if ( null === $weight || null === $weight_unit || (float) $weight <= 0 ) {
			return null;
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

		if ( 'lbs' === $store_weight_unit ) { // WC uses 'lb' internally
			$store_weight_unit = 'lb';
		}

		if ( $shopify_unit_key === $store_weight_unit ) {
			return (float) $weight;
		}

		// Use wc_get_weight for conversion if possible (more robust)
		if ( function_exists('wc_get_weight') ) {
			$converted = wc_get_weight( (float) $weight, $store_weight_unit, $shopify_unit_key );
			return is_numeric($converted) ? (float) $converted : null;
		}

		// Fallback manual conversion (less robust)
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
			WP_CLI::warning( "Could not find manual conversion factor from '{$shopify_unit_key}' to '{$store_weight_unit}'. Returning original weight {$weight}." );
			return (float) $weight;
		}

		return (float) $weight * $conversion_factors[ $shopify_unit_key ][ $store_weight_unit ];
	}

	/**
	 * Basic sanitization for product description HTML.
	 * Can be expanded based on specific needs.
	 *
	 * @param string $html Raw description HTML.
	 * @return string Sanitized HTML.
	 */
	private function sanitize_product_description( string $html ): string {
		// Allow basic HTML tags suitable for descriptions
		// Consider using wp_kses() with a specific allowed HTML array for more control
		return trim( $html );
	}

	/**
	 * Checks if a specific field should be processed based on constructor args.
	 * Placeholder - needs integration with how fields are passed from Controller.
	 *
	 * @param string $field_key The field key (e.g., 'title', 'price').
	 * @return bool True if the field should be processed.
	 */
	private function should_process( string $field_key ): bool {
		// If fields_to_process is empty, assume all fields should be processed
		if ( empty( $this->fields_to_process ) ) {
			return true;
		}
		return in_array( $field_key, $this->fields_to_process, true );
	}

	/**
	 * Gets the default product fields to process if not specified.
	 *
	 * @return array Default fields.
	 */
	private function get_default_product_fields(): array {
		return array(
			'title',
			'slug',
			'description',
			'status',
			'date_created',
			'catalog_visibility',
			'category', // Corresponds to collections
			'tag', // Corresponds to tags
			'price',
			'sku',
			'stock',
			'weight',
			'brand', // Corresponds to vendor
			'images',
			'seo', // Corresponds to metafields
			'attributes', // Corresponds to options/variants
		);
	}
} 