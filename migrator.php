<?php
/**
 * Plugin Name:     Migrator
 * Description:     CLI commands to migrate data from Shopify to Woo.
 * Version:         0.1.0
 *
 * @package         Migrator
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Only include the config.php file if exists
if ( file_exists( __DIR__ . '/config.php' ) ) {
	require_once __DIR__ . '/config.php';
}

class Migrator_CLI extends WP_CLI_Command {
	private $additional_product_data, $migration_data, $order_items_mapping, $order_tax_rate_ids_mapping, $assoc_args, $fields;

	/**
	 * 1. Fetch orders from Shopify then loop through them.
	 * 2. Check if the corresponding Woo order has tags.
	 * 3. Set the tags for Woo order if need.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Run the command without actually doing anything
	 *
	 * [--before]
	 * : Query Order before this date. ISO 8601 format.
	 *
	 * [--after]
	 * : Query Order after this date. ISO 8601 format.
	 *
	 * [--limit]
	 * : Limit the total number of orders to process.
	 *
	 * [--perpage]
	 * : Limit the number of orders to process each time.
	 *
	 * [--next]
	 * : Next page link from Shopify.
	 *
	 * @when after_wp_load
	 */
	public function fix_missing_order_tags( $args, $assoc_args ) {
		$this->health_check();

		$dry_run   = isset( $assoc_args['dry-run'] ) ? true : false;
		$before    = isset( $assoc_args['before'] ) ? $assoc_args['before'] : null;
		$after     = isset( $assoc_args['after'] ) ? $assoc_args['after'] : null;
		$limit     = isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : 1000;
		$perpage   = isset( $assoc_args['perpage'] ) ? $assoc_args['perpage'] : 50;
		$next_link = isset( $assoc_args['next'] ) ? $assoc_args['next'] : '';

		if ( $next_link ) {
			$response = $this->rest_request( $next_link );
		} else {
			$response = $this->rest_request(
				'orders.json?',
				array(
					'status'         => 'any',
					'limit'          => $perpage,
					'fields'         => 'id,order_number,tags,created_at',
					'created_at_max' => $before,
					'created_at_min' => $after,
				)
			);
		}

		$response_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response_data->orders ) ) {
			WP_CLI::error( 'Could not find order in Shopify.' );
		}

		WP_CLI::line( sprintf( 'Found %d orders in Shopify. Processing %d orders.', count( $response_data->orders ), min( $limit, $perpage, count( $response_data->orders ) ), count( $response_data->orders ) ) );

		for ( $i = 0; $i < min( $limit, $perpage, count( $response_data->orders ) ); $i++ ) {
			$shopify_order = $response_data->orders[ $i ];
			WP_CLI::line( '-------------------------------' );
			$order_number = $shopify_order->order_number;

			WP_CLI::line( sprintf( 'Processing Shopify order: %d, created @ %s', $order_number, $shopify_order->created_at ) );

			if ( ! $shopify_order->tags ) {
				WP_CLI::line( sprintf( 'Order %d has no tags.', $order_number ) );
				continue;
			}

			$tags = explode( ',', $shopify_order->tags );

			// search for the order by the order number
			$query_args = array(
				'numberposts' => 1,
				'meta_key'    => '_order_number',
				'meta_value'  => $order_number,
				'post_type'   => 'shop_order',
				'post_status' => 'any',
				'fields'      => 'ids',
			);

			$posts            = get_posts( $query_args );
			list( $order_id ) = ! empty( $posts ) ? $posts : null;

			if ( ! $order_id ) {
				WP_CLI::error( 'Could not find the corresponding order in WooCommerce.' );
				continue;
			}

			WP_CLI::line( sprintf( 'Found the WooCommerce order: %d', $order_id ) );

			// Set tag for the current order.
			foreach ( $tags as $tag ) {
				$tag = trim( $tag );

				if ( ! $tag ) {
					WP_CLI::line( 'Invalid tag, skipping.' );
					continue;
				}

				WP_CLI::line( sprintf( '- processing tag: "%s"', $tag, $order_id ) );

				if ( $dry_run ) {
					WP_CLI::line( 'Dry run, skipping.' );
					continue;
				}

				// Find the term id if it exists.
				$term = get_term_by( 'name', $tag, 'wcot_order_tag', ARRAY_A );

				if ( ! $term ) {
					$term = wp_insert_term( $tag, 'wcot_order_tag' );
				}

				wp_set_post_terms( $order_id, $term['term_id'], 'wcot_order_tag', true );
			}
		}

		WP_CLI::line( '===============================' );

		$next_link = $this->get_rest_next_link( $response );
		if ( $next_link && $limit > $perpage ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'There are more orders to process.' );
			$this->fix_missing_order_tags(
				array(),
				array(
					'next'  => $next_link,
					'limit' => $limit - $perpage,
				)
			);
		} else {
			WP_CLI::success( 'All orders have been processed.' );
		}
	}

	/**
	 * Migrate products from Shopify to WooCommerce.
   *
	 * ## OPTIONS
	 *
	 * [--before]
	 * : Query Order before this date. ISO 8601 format.
	 *
	 * [--after]
	 * : Query Order after this date. ISO 8601 format.
	 *
	 * [--limit]
	 * : Limit the total number of orders to process.
	 *
	 * [--perpage]
	 * : Limit the number of orders to process each time.
	 *
	 * [--next]
	 * : Next page link from Shopify.
	 *
	 * [--status]
	 * : Product status.
	 *
	 * [--ids]
	 * : Query products by IDs.
	 *
	 * [--exclude]
	 * : Exclude products by IDs or by SKU pattern.
	 *
	 * [--handle]
	 * : Query products by handles
	 *
	 * [--product-type]
	 * : single or variable or all.
	 *
	 * [--no-update]
	 * : Force create new products instead of updating existing one base on the handle.
	 *
	 * [--fields]
	 * : Only migrate/update selected fields.
	 *
	 * [--exclude-fields]
	 * : Exclude selected fields from update.
	 *
	 * [--remove-orphans]
	 * : Remove orphans order items
	 *
	 * Example:
	 * wp migrator products --limit=100 --perpage=10 --status=active --product-type=single --exclude="CANAL_SKU_*"
	 *
	 * @when after_wp_load
	 */
	public function products( $args, $assoc_args ) {
		$this->health_check();
		$this->assoc_args = $assoc_args;

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
		$limit        = isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : 1000;
		$perpage      = isset( $assoc_args['perpage'] ) ? $assoc_args['perpage'] : 50;
		$next_link    = isset( $assoc_args['next'] ) ? $assoc_args['next'] : '';
		$status       = isset( $assoc_args['status'] ) ? $assoc_args['status'] : 'active';
		$ids          = isset( $assoc_args['ids'] ) ? $assoc_args['ids'] : null;
		$exclude      = isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array();
		$handle       = isset( $assoc_args['handle'] ) ? $assoc_args['handle'] : null;
		$product_type = isset( $assoc_args['product-type'] ) ? $assoc_args['product-type'] : 'all';
		$no_update    = isset( $assoc_args['no-update'] ) ? true : false;

		if ( $next_link ) {
			$response = $this->rest_request( $next_link );
		} else {
			$response = $this->rest_request(
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

		$response_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response_data->products ) ) {
			WP_CLI::error( 'No Shopify products found.' );
		}

		WP_CLI::line( sprintf( 'Found %d products in Shopify. Processing %d products.', count( $response_data->products ), min( $limit, $perpage, count( $response_data->products ) ) ) );

		for ( $i = 0; $i < min( $limit, $perpage, count( $response_data->products ) ); $i++ ) {
			$shopify_product = $response_data->products[ $i ];

			if ( in_array( $shopify_product->id, $exclude ) || $this->preg_match_array( $shopify_product->variants[0]->sku, $exclude ) ) {
				WP_CLI::line( sprintf( 'Product %s is excluded. Skipping...', $shopify_product->handle ) );
				continue;
			}

			WP_CLI::line( 'Fetching additional product data...' );
			$this->fetch_additional_shopify_product_data( $shopify_product );

			// Check if product is single or variable by checking how many
			// variants it has.
			$curent_product_type = 'single';
			if ( $this->is_variable_product( $shopify_product ) ) {
				$curent_product_type = 'variable';
			}

			if ( $product_type !== 'all' && $product_type !== $curent_product_type ) {
				WP_CLI::line( sprintf( 'Product %s is %s. Skipping...', $shopify_product->handle, $curent_product_type ) );
				continue;
			}

			// Check if the product already exists in Woo by handle.
			$woo_product = $this->get_corresponding_woo_product( $shopify_product );

			if ( $woo_product ) {
				WP_CLI::line( sprintf( 'Product %s already exists (%s). %s...', $shopify_product->handle, $woo_product->get_id(), $no_update ? 'Skipping' : 'Updating' ) );

				if ( $no_update ) {
					continue;
				}
			} else {
				WP_CLI::line( sprintf( 'Product %s does not exist. Creating...', $shopify_product->handle ) );
			}

			$this->create_or_update_woo_product( $shopify_product, $woo_product );
		}

		WP_CLI::line( '===============================' );

		$next_link = $this->get_rest_next_link( $response );
		if ( $next_link && $limit > $perpage ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'There are more products to process.' );
			$this->products(
				array(),
				array(
					'next'    => $next_link,
					'limit'   => $limit - $perpage,
					'exclude' => implode( ',', $exclude ),
				)
			);
		} else {
			WP_CLI::success( 'All products have been processed.' );
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

	private function health_check() {
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

	private function rest_request( $endpoint, $body = array() ) {
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

	private function graphql_request( $body ) {
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

	private function get_rest_next_link( $response ) {
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

		// Check if the product already exists in Woo by SKU. Only if the
		// product is a single product.
		if ( ! $this->is_variable_product( $shopify_product ) && $shopify_product->variants[0]->sku ) {
			$woo_product = wc_get_product_id_by_sku( $shopify_product->variants[0]->sku );

			if ( $woo_product ) {
				return wc_get_product( $woo_product );
			}
		}

		// Check if the product already exists in Woo by handle.
		$woo_product = get_page_by_path( $shopify_product->handle, OBJECT, 'product' );

		if ( $woo_product ) {
			return wc_get_product( $woo_product );
		}

		return null;
	}

	private function is_variable_product( $shopify_product ) {
		return count( $shopify_product->variants ) > 1;
	}

	private function get_woo_product_status( $shopify_product ) {
		$woo_product_status = 'draft';

		if ( $shopify_product->status === 'active' ) {
			$woo_product_status = 'publish';
		}

		return $woo_product_status;
	}

	private function should_process( $field ) {
		return in_array( $field, $this->fields );
	}

	private function create_or_update_woo_product( $shopify_product, $woo_product = null ) {
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
				if ( $this->additional_product_data->onlineStoreUrl === null ) {
					$product->set_catalog_visibility( 'hidden' );
				} else {
					$this->migration_data['original_url'] = $this->additional_product_data->onlineStoreUrl;
				}
			}
		}

		if ( $this->should_process( 'category' ) ) {
			$product->set_category_ids( $this->get_woo_product_category_ids() );
		}

		if ( $this->should_process( 'tag' ) ) {
			$product->set_tag_ids( $this->get_woo_product_tag_ids( $shopify_product ) );
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
				$product->set_sku( $shopify_product->variants[0]->sku );
			}
			if ( $this->should_process( 'stock' ) ) {
				$product->set_manage_stock( $shopify_product->variants[0]->inventory_management === 'shopify' );
				$product->set_stock_status( $shopify_product->variants[0]->inventory_quantity === 'deny' ? 'outofstock' : 'instock' );
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

		$product->save();

		// Variations.
		if ( $this->is_variable_product( $shopify_product ) ) {
			$this->create_or_update_woo_product_variations( $shopify_product, $product );
		}

		if ( $this->should_process( 'seo' ) ) {
			$this->update_seo_title_description( $shopify_product, $product );
		}

		// Migration metas.
		foreach ( $this->additional_product_data->metafields->edges as $field ) {
			$key                                        = sprintf( '%s_%s', $field->node->namespace, $field->node->key );
			$this->migration_data['metafields'][ $key ] = $field->node->value;
		}

		$product->update_meta_data( '_migration_data', $this->migration_data );
		$product->update_meta_data( '_original_product_id', $shopify_product->id ); // For searching later

		$product->save();
		WP_CLI::line( 'Woo Product ID: ' . $product->get_id() );
	}

	private function get_converted_weight( $weight, $weight_unit ) {
		$store_weight_unit = get_option( 'woocommerce_weight_unit' );
		if ( $store_weight_unit === 'lbs' ) {
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

	private function fetch_additional_shopify_product_data( $shopify_product ) {
		$response = $this->graphql_request(
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
		sleep( 1 ); // Pause the execution for 1 second to avoid rate limit.
	}

	private function get_woo_product_category_ids() {
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

		return $category_ids;
	}

	private function get_woo_product_tag_ids( $shopify_product ) {
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

		return $tag_ids;
	}

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

	private function upload_images( $shopify_product, $product ) {
		foreach ( $shopify_product->images as $image ) {
			// Check if the image has already been uploaded.
			if ( isset( $this->migration_data['images_mapping'][ $image->id ] ) && wp_attachment_is_image( $this->migration_data['images_mapping'][ $image->id ] ) ) {
				continue;
			}

			// Upload the image to the media library.
			$image_id = media_sideload_image( $image->src, $product->get_id(), '', 'id' );

			if ( is_wp_error( $image_id ) ) {
				WP_CLI::line( sprintf( 'Error uploading %s: %s', $image->src, $image_id->get_error_message() ) );
			}

			// Save the mapping.
			$this->migration_data['images_mapping'][ $image->id ] = $image_id;
		}

		$this->migration_data['images_mapping'] = $this->migration_data['images_mapping'];
	}

	private function get_woo_product_image_id( $shopify_product ) {
		if ( empty( $shopify_product->images ) ) {
			return 0;
		}

		// Get the image ID from mapping.
		return $this->migration_data['images_mapping'][ $shopify_product->images[0]->id ];
	}

	private function get_woo_product_gallery_image_ids( $shopify_product ) {
		if ( count( $this->migration_data['images_mapping'] ) < 2 ) {
			return array();
		}

		return array_diff( array_values( $this->migration_data['images_mapping'] ), array( $this->get_woo_product_image_id( $shopify_product, $this->migration_data['images_mapping'] ) ) );
	}

	private function update_seo_title_description( $shopify_product, WC_Product $product ) {
		$current_seo_title       = $product->get_meta( '_yoast_wpseo_title' );
		$current_seo_description = $product->get_meta( '_yoast_wpseo_metadesc' );

		$title       = $product->get_title();
		$description = $product->get_short_description() ?: get_the_excerpt( $product->get_id() );

		foreach ( $this->additional_product_data->metafields->edges as $field ) {
			// $key                                        = sprintf( '%s_%s',
			// $field->node->namespace, $field->node->key );
			if ( $field->node->namespace === 'global' && $field->node->key === 'title_tag' ) {
				$title = $field->node->value;
			}

			if ( $field->node->namespace === 'global' && $field->node->key === 'description_tag' ) {
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

	private function create_or_update_woo_product_variations( $shopify_product, $product ) {
		$attribute_taxonomy_mapping = array();

		if ( $this->should_process( 'attributes' ) ) {
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
				function( $taxonomy, $value ) {
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
			$product->save();
		}

		foreach ( $shopify_product->variants as $variant ) {
			WP_CLI::line( 'Processing variant ' . $variant->id );
			$variation = new WC_Product_Variation();

			// Check if the variant has been handled by our migrator before.
			if ( in_array( $variant->id, array_keys( $this->migration_data['variations_mapping'] ) ) ) {
				$_variation = wc_get_product( $this->migration_data['variations_mapping'][ $variant->id ] );
				if ( is_a( $_variation, 'WC_Product_Variation' ) ) {
					$variation = $_variation;
					WP_CLI::line( 'Found existing variation (ID: ' . $variation->get_id() . '). Updating.' );
				}
			} elseif ( $variant->sku ) {
				$check_id      = wc_get_product_id_by_sku( $variant->sku );
				$check_product = wc_get_product( $check_id );

				if ( is_a( $check_product, 'WC_Product_Variation' ) ) {
					// The product is already a variation.
					$variation = new WC_Product_Variation( $check_product );
					WP_CLI::line( 'Found existing variation (SKU: ' . $variation->get_sku() . '). Updating.' );
				} else {
					// The SKU was incorrectly assigned to a product. Remove the SKU
					// from the product to set it to a new variation.
					if ( is_a( $check_product, 'WC_Product' ) ) {
						$check_product->set_sku( '' );
						$check_product->save();
					}
				}
			}

			$variation->set_parent_id( $product->get_id() );
			$variation->set_menu_order( $variant->position );
			$variation->set_status( 'publish' );

			if ( $this->should_process( 'stock' ) ) {
				$variation->set_manage_stock( $variant->inventory_management === 'shopify' );
				$variation->set_stock_quantity( $variant->inventory_quantity );
				$variation->set_stock_status( $variant->inventory_quantity === 'deny' ? 'outofstock' : 'instock' );
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
				if ( $variant->compare_at_price and $variant->compare_at_price > $variant->price ) {
					$variation->set_regular_price( $variant->compare_at_price );
					$variation->set_sale_price( $variant->price );
				} else {
					$variation->set_regular_price( $variant->price );
					$variation->set_sale_price( '' );
				}
			}

			if ( $this->should_process( 'sku' ) ) {
				if ( $variant->sku ) {
					$variation->set_sku( $variant->sku );
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

			$variation->save();

			$this->migration_data['variations_mapping'][ $variant->id ] = $variation->get_id();
		}

		$this->clean_up_orphan_variations( $product );
	}

	private function clean_up_orphan_variations( $product ) {
		if ( ! isset( $this->assoc_args['remove-orphans'] ) ) {
			return;
		}

		$variations = $product->get_children();

		foreach ( $variations as $variation_id ) {
			if ( ! in_array( $variation_id, array_values( $this->migration_data['variations_mapping'] ) ) ) {
				WP_CLI::line( 'Deleting orphan variation (ID: ' . $variation_id . ')' );
				wp_delete_post( $variation_id, true );
			}
		}
	}

	private function sanitize_product_description( $html, $tags = array() ) {
		$html = mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );

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
	 * Supports matching against an array of regular expressions, and will do a glob match so things like CANAL_* will match every product that starts with CANAL_
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
	 * Migrate Shopify orders to WooCommerce.
	 *
	 * ## OPTIONS
	 *
	 * [--before]
	 * : Query Order before this date. ISO 8601 format.
	 *
	 * [--after]
	 * : Query Order after this date. ISO 8601 format.
	 *
	 * [--limit]
	 * : Limit the total number of orders to process.
	 *
	 * [--perpage]
	 * : Limit the number of orders to process each time.
	 *
	 * [--next]
	 * : Next page link from Shopify.
	 *
	 * [--status]
	 * : Order status.
	 *
	 * [--ids]
	 * : Query orders by IDs.
	 *
	 * [--exclude]
	 * : Exclude orders by IDs.
	 *
	 * [--no-update]
	 * : Skip existing order without updating.
	 *
	 * [--sorting]
	 * : Sort the response. Default to 'id asc'.
	 *
	 * [--remove-orphans]
	 * : Remove orphans order items
	 *
	 * @when after_wp_load
	 */
	public function orders( $args, $assoc_args ) {
		$this->health_check();
		$this->assoc_args = $assoc_args;

		if ( is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' ) ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'We need to disable WooCommerce Sequential Order Numbers plugin while migration to ensure the order number is set correctly. The plugin will be enabled again after migration finished.' );
			WP_CLI::runcommand( 'plugin deactivate woocommerce-sequential-order-numbers' );
		}

		$before    = isset( $assoc_args['before'] ) ? $assoc_args['before'] : null;
		$after     = isset( $assoc_args['after'] ) ? $assoc_args['after'] : null;
		$limit     = isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : 1000;
		$perpage   = isset( $assoc_args['perpage'] ) ? $assoc_args['perpage'] : 50;
		$next_link = isset( $assoc_args['next'] ) ? $assoc_args['next'] : '';
		$status    = isset( $assoc_args['status'] ) ? $assoc_args['status'] : 'any';
		$ids       = isset( $assoc_args['ids'] ) ? $assoc_args['ids'] : null;
		$exclude   = isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array();
		$no_update = isset( $assoc_args['no-update'] ) ? true : false;
		$sorting   = isset( $assoc_args['sorting'] ) ? $assoc_args['sorting'] : 'id asc';

		if ( $next_link ) {
			$response = $this->rest_request( $next_link );
		} else {
			$response = $this->rest_request(
				'orders.json',
				array(
					'limit'          => $perpage,
					'created_at_max' => $before,
					'created_at_min' => $after,
					'status'         => $status,
					'ids'            => $ids,
					'order'          => $sorting,
				)
			);
		}

		$response_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response_data->orders ) ) {
			WP_CLI::error( 'No Shopify orders found.' );
		}

		WP_CLI::line( sprintf( 'Found %d orders in Shopify. Processing %d orders.', count( $response_data->orders ), min( $limit, $perpage, count( $response_data->orders ) ) ) );

		for ( $i = 0; $i < min( $limit, $perpage, count( $response_data->orders ) ); $i++ ) {
			$shopify_order = $response_data->orders[ $i ];

			if ( in_array( $shopify_order->id, $exclude ) ) {
				WP_CLI::line( sprintf( 'Order %s is excluded. Skipping...', $shopify_order->order_number ) );
				continue;
			}

			// Check if the order exists in WooCommerce.
			$woo_order = $this->get_corresponding_woo_order( $shopify_order );

			if ( $woo_order ) {
				WP_CLI::line( sprintf( 'Order %s already exists (%s). %s...', $shopify_order->order_number, $woo_order->get_id(), $no_update ? 'Skipping' : 'Updating' ) );

				if ( $no_update ) {
					continue;
				}
			} else {
				WP_CLI::line( sprintf( 'Order %s does not exist. Creating...', $shopify_order->order_number ) );
			}

			$this->create_or_update_woo_order( $shopify_order, $woo_order );
		}

		WP_CLI::line( '===============================' );

		$next_link = $this->get_rest_next_link( $response );
		if ( $next_link && $limit > $perpage ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'There are more orders to process.' );
			$this->orders(
				array(),
				array_merge(
					$assoc_args,
					array(
						'next'  => $next_link,
						'limit' => $limit - $perpage,
					)
				)
			);
		} else {
			WP_CLI::success( 'All orders have been processed.' );

			if ( is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php' ) ) {
				WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Enabling WooCommerce Sequential Order Numbers plugin.' );
				WP_CLI::runcommand( 'plugin activate woocommerce-sequential-order-numbers' );
			}
		}
	}

	private function get_corresponding_woo_order( $shopify_order ) {
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_original_order_id',
				'meta_value' => $shopify_order->id,
			)
		);

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		// Find the order by Shopify order number.
		$orders = wc_get_orders(
			array(
				'meta_key'   => '_order_number',
				'meta_value' => $shopify_order->order_number,
			)
		);

		if ( ! empty( $orders ) ) {
			return $orders[0];
		}

		return null;
	}

	private function set_placeholder_billing_email( $order ) {
		// Create username from first name, last name, and phone number.
		$username  = $order->get_billing_first_name() ?? $order->get_shipping_first_name();
		$username .= $order->get_billing_last_name() ?? $order->get_shipping_last_name();
		$username .= substr( $order->get_billing_phone() ?? $order->get_shipping_phone(), -3 );
		$username  = preg_replace( '/[^a-zA-Z0-9]/', '', $username );
		$username  = strtolower( $username );
		$username  = str_replace( ' ', '', $username );

		if ( $username ) {
			$email = $username . '@example.com.invalid';

			$order->set_billing_email( $email );
		}

		$order->set_customer_id( 0 );
		$order->save();
	}

	private function create_or_update_woo_order( $shopify_order, $woo_order ) {
		$order = new WC_Order( $woo_order );
		$order->save();

		$this->order_items_mapping = $order->get_meta( '_order_items_mapping', true ) ? $order->get_meta( '_order_items_mapping', true ) : array();

		// Store the original order id for future reference.
		$order->update_meta_data( '_original_order_id', $shopify_order->id );
		$order->update_meta_data( '_order_number', $shopify_order->order_number );

		// Prevent Points and Rewards add order notes.
		$order->update_meta_data( '_wc_points_earned', true );

		// Update order status.
		$order->update_status( $this->get_woo_order_status( $shopify_order->financial_status, $shopify_order->fulfillment_status ) );
		$order->set_order_stock_reduced( true );

		// Update order dates.
		$order->set_date_created( $shopify_order->created_at );
		$order->set_date_modified( $shopify_order->updated_at );
		$order->set_date_paid( $shopify_order->processed_at );
		$order->set_date_completed( $shopify_order->closed_at );

		// Update order totals
		$order->set_total( $shopify_order->total_price );
		$order->set_shipping_total( $shopify_order->total_shipping_price_set->shop_money->amount );
		$order->set_discount_total( $shopify_order->total_discounts );

		$this->process_order_tags( $order, $shopify_order );
		$this->process_order_addresses( $order, $shopify_order );

		if ( $shopify_order->email ) {
			$this->create_or_assign_customer( $order, $shopify_order );
		} else {
			$this->set_placeholder_billing_email( $order );
		}

		// Tax must be processed before line items.
		$this->process_tax_lines( $order, $shopify_order );
		$this->maybe_remove_orphan_items( $order, $shopify_order );
		$this->process_line_items( $order, $shopify_order );
		$this->process_shipping_lines( $order, $shopify_order );
		$this->process_discount_lines( $order, $shopify_order );

		$this->process_shipment_tracking( $order, $shopify_order );

		$order->update_meta_data( '_order_items_mapping', $this->order_items_mapping );
		$order->save();

		// Refunds
		$this->process_order_refunds( $order, $shopify_order );
	}

	private function create_or_assign_customer( $order, $shopify_order ) {
		// Check if the customer exists in WooCommerce.
		$customer = get_user_by( 'email', $shopify_order->email );

		if ( ! $customer ) {
			WP_CLI::line( sprintf( 'Customer %s does not exist. Creating...', $shopify_order->email ) );

			// Create a new customer using Woo functions.
			$customer_id = wc_create_new_customer(
				$shopify_order->email,
				wc_create_new_customer_username( $shopify_order->email ),
				wp_generate_password()
			);

			if ( is_wp_error( $customer_id ) ) {
				WP_CLI::error( sprintf( 'Error creating customer %s: %s', $shopify_order->email, $customer_id->get_error_message() ) );
			}

			$customer = new WC_Customer( $customer_id );
			$customer->set_first_name( $shopify_order->customer->first_name );
			$customer->set_last_name( $shopify_order->customer->last_name );

			$customer->set_billing_first_name( $shopify_order->billing_address->first_name );
			$customer->set_billing_last_name( $shopify_order->billing_address->last_name );
			$customer->set_billing_company( $shopify_order->billing_address->company );
			$customer->set_billing_address_1( $shopify_order->billing_address->address1 );
			$customer->set_billing_address_2( $shopify_order->billing_address->address2 );
			$customer->set_billing_city( $shopify_order->billing_address->city );
			$customer->set_billing_state( $shopify_order->billing_address->province_code );
			$customer->set_billing_postcode( $shopify_order->billing_address->zip );
			$customer->set_billing_country( $shopify_order->billing_address->country );
			$customer->set_billing_phone( $shopify_order->billing_address->phone );
			$customer->set_billing_email( $shopify_order->email );

			$customer->set_shipping_first_name( $shopify_order->shipping_address->first_name );
			$customer->set_shipping_last_name( $shopify_order->shipping_address->last_name );
			$customer->set_shipping_company( $shopify_order->shipping_address->company );
			$customer->set_shipping_address_1( $shopify_order->shipping_address->address1 );
			$customer->set_shipping_address_2( $shopify_order->shipping_address->address2 );
			$customer->set_shipping_city( $shopify_order->shipping_address->city );
			$customer->set_shipping_state( $shopify_order->shipping_address->province_code );
			$customer->set_shipping_postcode( $shopify_order->shipping_address->zip );
			$customer->set_shipping_country( $shopify_order->shipping_address->country );
			$customer->set_shipping_phone( $shopify_order->shipping_address->phone );

			$customer->save();
		} else {
			WP_CLI::line( sprintf( 'Customer %s exists. Assigning...', $shopify_order->email ) );
			$customer_id = $customer->ID;
		}

		$order->set_customer_id( $customer_id );
		$order->save();
	}

	private function process_order_addresses( WC_Order $order, $shopify_order ) {
		// Update order billing address.
		$order->set_billing_first_name( $shopify_order->billing_address->first_name );
		$order->set_billing_last_name( $shopify_order->billing_address->last_name );
		$order->set_billing_company( $shopify_order->billing_address->company );
		$order->set_billing_address_1( $shopify_order->billing_address->address1 );
		$order->set_billing_address_2( $shopify_order->billing_address->address2 );
		$order->set_billing_city( $shopify_order->billing_address->city );
		$order->set_billing_state( $shopify_order->billing_address->province_code );
		$order->set_billing_postcode( $shopify_order->billing_address->zip );
		$order->set_billing_country( $shopify_order->billing_address->country_code );
		$order->set_billing_phone( $shopify_order->billing_address->phone );

		// Update order shipping address.
		$order->set_shipping_first_name( $shopify_order->shipping_address->first_name );
		$order->set_shipping_last_name( $shopify_order->shipping_address->last_name );
		$order->set_shipping_company( $shopify_order->shipping_address->company );
		$order->set_shipping_address_1( $shopify_order->shipping_address->address1 );
		$order->set_shipping_address_2( $shopify_order->shipping_address->address2 );
		$order->set_shipping_city( $shopify_order->shipping_address->city );
		$order->set_shipping_state( $shopify_order->shipping_address->province_code );
		$order->set_shipping_postcode( $shopify_order->shipping_address->zip );
		$order->set_shipping_country( $shopify_order->shipping_address->country_code );
		$order->set_shipping_phone( $shopify_order->shipping_address->phone );

		$order->save();
	}

	private function find_line_item_product( $line_item ) {
		$product_id   = 0;
		$variation_id = 0;
		if ( $line_item->sku ) {
			$_id = wc_get_product_id_by_sku( $line_item->sku );
			if ( $_id ) {
				$product_id = $_id;
				$_product   = wc_get_product( $_id );
				if ( is_a( $_product, 'WC_Product' ) && $_product->is_type( 'variation' ) ) {
					$product_id   = $_product->get_parent_id();
					$variation_id = $_product->get_id();
				}
			}
		} elseif ( $line_item->product_exists ) {
			$_products = wc_get_products(
				array(
					'limit'      => 1,
					'meta_key'   => '_original_product_id',
					'meta_value' => $line_item->product_id,
				)
			);

			if ( count( $_products ) === 1 ) {
				$product_id = $_products[0]->get_id();
				if ( $_products[0]->is_type( 'variable' ) ) {
					$migration_data = $_products[0]->get_meta( '_migration_data', true ) ?: array();
					if ( isset( $migration_data['variations_mapping'][ $line_item->variant_id ] ) ) {
						$variation_id = $migration_data['variations_mapping'][ $line_item->variant_id ];
					}
				}
			}
		}

		return array( $product_id, $variation_id );
	}

	private function maybe_remove_orphan_items( $order, $shopify_order ) {
		if ( ! isset( $this->assoc_args['remove-orphans'] ) ) {
			return;
		}

		foreach ( $order->get_items( array( 'line_item', 'shipping' ) ) as $item ) {
			if ( ! in_array( $item->get_id(), $this->order_items_mapping ) ) {
				WP_CLI::line( sprintf( 'Removing orphan item %d', $item->get_id() ) );
				$order->remove_item( $item->get_id() );
			}
		}

		$order->save();
	}

	private function process_line_items( $order, $shopify_order ) {
		foreach ( $shopify_order->line_items as $line_item ) {
			$line_item_id = 0;
			if ( isset( $this->order_items_mapping[ $line_item->id ] ) ) {
				WP_CLI::line( sprintf( 'Line item %d already exists (%d). Updating.', $line_item->id, $this->order_items_mapping[ $line_item->id ] ) );
				$line_item_id = $this->order_items_mapping[ $line_item->id ];
			} else {
				WP_CLI::line( sprintf( 'Creating line item %d', $line_item->id ) );
			}

			// Create a product line item
			$item = new WC_Order_Item_Product( $line_item_id );

			list( $product_id, $variation_id ) = $this->find_line_item_product( $line_item );

			if ( $product_id ) {
				$item->set_product_id( $product_id );
			}

			if ( $variation_id ) {
				$item->set_variation_id( $variation_id );
			}

			$item->set_quantity( $line_item->quantity );
			$item->set_subtotal( $line_item->price * $line_item->quantity );
			$item->set_total( $line_item->price * $line_item->quantity - $line_item->total_discount );
			$item->set_name( $line_item->name );

			// Taxes
			$this->set_line_item_taxes( $item, $line_item );

			$item->save();

			$order->add_item( $item );
			$this->order_items_mapping[ $line_item->id ] = $item->get_id();
		}

		$order->save();
	}

	private function set_line_item_taxes( $line_item, $shopify_line_item ) {
		if ( empty( $this->order_tax_rate_ids_mapping ) || empty( $shopify_line_item->tax_lines ) ) {
			return;
		}

		$taxes = array(
			'subtotal' => array(),
			'total'    => array(),
		);

		foreach ( $shopify_line_item->tax_lines as $tax_line ) {
			if ( ! isset( $this->order_tax_rate_ids_mapping[ $tax_line->title ] ) ) {
				continue;
			}
			$tax_rate_id                       = $this->order_tax_rate_ids_mapping[ $tax_line->title ];
			$taxes['subtotal'][ $tax_rate_id ] = $tax_line->price;
			$taxes['total'][ $tax_rate_id ]    = $tax_line->price;
		}

		$line_item->set_taxes( $taxes );
	}

	private function process_tax_lines( WC_Order $order, $shopify_order ) {
		$order->remove_order_items( 'tax' );
		$this->order_tax_rate_ids_mapping = array();

		foreach ( $shopify_order->tax_lines as $index => $tax_line ) {
			$item = new WC_Order_Item_Tax();
			$item->set_rate_id( $index );
			$item->set_label( $tax_line->title );
			$item->set_tax_total( $tax_line->price );
			$item->set_rate_percent( $tax_line->rate );

			$order->add_item( $item );
			$this->order_tax_rate_ids_mapping[ $tax_line->title ] = $index;
		}

		$order->save();
	}

	private function process_shipping_lines( $order, $shopify_order ) {
		foreach ( $shopify_order->shipping_lines as $shipping_line ) {
			$line_item_id = 0;
			if ( isset( $this->order_items_mapping[ $shipping_line->id ] ) ) {
				WP_CLI::line( sprintf( 'Shipping line item %d already exists (%d). Updating.', $shipping_line->id, $this->order_items_mapping[ $shipping_line->id ] ) );
				$line_item_id = $this->order_items_mapping[ $shipping_line->id ];
			} else {
				WP_CLI::line( sprintf( 'Creating shipping line item %d', $shipping_line->id ) );
			}

			$item = new WC_Order_Item_Shipping( $line_item_id );
			$item->set_method_title( $shipping_line->title );
			$item->set_total( $shipping_line->price );
			$this->set_line_item_taxes( $item, $shipping_line );
			$item->save();
			$order->add_item( $item );
			$this->order_items_mapping[ $shipping_line->id ] = $item->get_id();
		}

		$order->save();
	}

	private function process_discount_lines( $order, $shopify_order ) {
		$order->remove_order_items( 'coupon' );

		foreach ( $shopify_order->discount_applications as $discount ) {
			$item = new WC_Order_Item_Coupon();
			$item->set_discount( $discount->value );
			if ( $discount->type === 'discount_code' ) {
				$item->set_code( $discount->code );
			} else {
				$item->set_code( $discount->title );
			}

			$order->add_item( $item );
		}

		$order->save();
	}

	private function process_order_tags( $order, $shopify_order ) {
		if ( ! taxonomy_exists( 'wcot_order_tag' ) ) {
			return;
		}

		$tags = explode( ',', $shopify_order->tags );

		foreach ( $tags as $tag ) {
			$tag = trim( $tag );

			if ( ! $tag ) {
				WP_CLI::line( 'Invalid tag, skipping.' );
				continue;
			}

			WP_CLI::line( sprintf( '- processing tag: "%s"', $tag, $order->get_id() ) );

			// Find the term id if it exists.
			$term = get_term_by( 'name', $tag, 'wcot_order_tag', ARRAY_A );

			if ( ! $term ) {
				$term = wp_insert_term( $tag, 'wcot_order_tag' );
			}

			wp_set_post_terms( $order->get_id(), $term['term_id'], 'wcot_order_tag', true );
		}
	}

	private function process_order_refunds( $order, $shopify_order ) {
		foreach ( $shopify_order->refunds as $shopify_refund ) {

			// Check if the refund exists
			$refunds = wc_get_orders(
				array(
					'limit'      => 1,
					'type'       => 'shop_order_refund',
					'meta_key'   => '_original_refund_id',
					'meta_value' => $shopify_refund->id,
				)
			);

			if ( count( $refunds ) > 0 ) {
				// Deleting the refund then create a new one so we can reuse the logic in wc_create_refund.
				WP_CLI::line( sprintf( 'Refund %d already exists (%d). Deleting to create a new one.', $shopify_refund->id, $refunds[0]->get_id() ) );
				wp_delete_post( $refunds[0]->get_id(), true );
			}

			// Refunded line items
			$refunded_line_items = array();
			foreach ( $shopify_refund->refund_line_items as $refund_line_item ) {
				$refunded_line_items[ $this->order_items_mapping[ $refund_line_item->line_item_id ] ] = array(
					'qty'          => $refund_line_item->quantity,
					'refund_total' => $refund_line_item->subtotal,
				);
			}

			$refund_total = 0;
			foreach ( $shopify_refund->transactions as $transaction ) {
				if ( $transaction->status === 'success' ) {
					$refund_total += $transaction->amount;
				}
			}

			// Create a refund
			$refund = wc_create_refund(
				array(
					'amount'       => $refund_total,
					'reason'       => $shopify_refund->note,
					'order_id'     => $order->get_id(),
					'line_items'   => $refunded_line_items,
					'date_created' => $shopify_refund->created_at,
				)
			);

			// Update refund date
			$refund->update_meta_data( '_refund_completed_date', $shopify_refund->processed_at );

			// Update refund transaction ID
			if ( count ( $shopify_refund->transactions ) > 0 && property_exists( $shopify_refund->transactions[0], 'receipt') && property_exists( $shopify_refund->transactions[0]->receipt, 'refund_transaction_id' ) ) {
				$refund->update_meta_data( '_transaction_id', $shopify_refund->transactions[0]->receipt->refund_transaction_id );
			}

			// Store the Shopify refund ID
			$refund->update_meta_data( '_original_refund_id', $shopify_refund->id );

			$refund->save();
		}
	}

	private function process_shipment_tracking( $order, $shopify_order ) {
		if ( ! class_exists( 'WC_Shipment_Tracking_Actions' ) ) {
			return;
		}

		if ( ! isset( $shopify_order->fulfillments ) ) {
			return;
		}

		WP_CLI::line( 'Processing shipment tracking.' );

		$st = WC_Shipment_Tracking_Actions::get_instance();
		$st->save_tracking_items( $order->get_id(), array() );

		foreach ( $shopify_order->fulfillments as $fulfillment ) {
			foreach ( $fulfillment->tracking_numbers as $index => $tracking_number ) {
				$st->add_tracking_item(
					$order->get_id(),
					array(
						'tracking_provider'        => '',
						'tracking_number'          => $tracking_number,
						'custom_tracking_link'     => $fulfillment->tracking_urls[ $index ],
						'custom_tracking_provider' => $fulfillment->tracking_company,
						'date_shipped'             => $fulfillment->created_at,
					)
				);
			}
		}
	}

	private function get_woo_order_status( $financial_status, $fulfillment_status ) {
		$financial_mapping = array(
			'pending'            => 'pending',
			'authorized'         => 'processing',
			'partially_paid'     => 'processing',
			'paid'               => 'processing',
			'partially_refunded' => 'processing',
			'refunded'           => 'refunded',
			'voided'             => 'cancelled',
		);

		// Define the mapping arrays for Shopify fulfillment status to WooCommerce order status
		$fulfillment_mapping = array(
			'fulfilled' => 'completed',
			'partial'   => 'processing',
			'pending'   => 'processing',
		);

		$woo_status = 'pending'; // Default WooCommerce order status if no mapping found

		// Map financial status
		if ( isset( $financial_mapping[ $financial_status ] ) ) {
			$woo_status = $financial_mapping[ $financial_status ];
		}

		// Map fulfillment status
		if ( isset( $fulfillment_mapping[ $fulfillment_status ] ) ) {
			$woo_status = $fulfillment_mapping[ $fulfillment_status ];
		}

		return $woo_status;
	}
}

add_action(
	'cli_init',
	function () {
		WP_CLI::add_command( 'migrator', 'Migrator_CLI' );
	}
);
