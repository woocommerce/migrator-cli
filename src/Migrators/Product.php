<?php

namespace Migrator\Migrators;

use Migrator\Interfaces\Source;

final class Product {

	const IDENTIFIER = 'product';

	private $args;

	private $source;

	private $workers;

	public function __construct( Source $source, array $workers) {
		$this->source  = $source;
		$this->workers = $workers;
	}

	public function run( $args = [] ) {	
		$this->args = $args;

		array_map(
			function( $original_product ) {
				$this->migrate( $original_product );
			},
			$this->source->get_products()
		);
	}

	private function migrate( $original_product ) {
		$product = $this->get_existing( $original_product );	

		if ( ! $product && $this->is_sync() ) {
			return;
		}

		if ( ! $product ) {
			$product = new \WC_Product();
			$product->update_meta_data( '_original_product_id', $original_product['id'] );
			$product->save();
		}

		// Processing the product data.
		foreach( $this->workers as $identifier => $worker ) {
			try {
				call_user_func( $worker, $product, $original_product );
			} catch ( \Exception $e ) {
				// Log the error.
			}
		}

		$product->save();
	}

	private function is_sync() {
		return isset( $this->args['sync'] ) && $this->args['sync'];
	}

	private function get_existing( $original_product ) {
		$id = $original_product['id'] ?? 0;
		$sku = $original_product['sku'] ?? '';
		$slug = $original_product['slug'] ?? '' ;

		// Try finding the product by original product ID.
		if ( $id ) {
			$woo_products = wc_get_products(
				array(
					'limit'      => 1,
					'meta_key'   => '_original_product_id',
					'meta_value' => $id,
				)
			);

			if ( count( $woo_products ) === 1 && is_a( $woo_products[0], 'WC_Product' ) ) {
				return wc_get_product( $woo_products[0] );
			}
		}

		// Check if the product already exists in Woo by SKU. Only if the
		// product is a single product.
		if ( $sku ) {
			$woo_product = wc_get_product_id_by_sku( $sku );

			if ( $woo_product ) {
				return wc_get_product( $woo_product );
			}
		}

		// Check if the product already exists in Woo by slug.
		if ( $slug ) {
			$woo_product = get_page_by_path( $slug, OBJECT, 'product' );

			if ( $woo_product ) {
				return wc_get_product( $woo_product );
			}
		}

		return null;
	}
}
