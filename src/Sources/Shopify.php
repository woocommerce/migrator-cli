<?php

namespace Migrator\Sources;

use Migrator\Interfaces\Source;

class Shopify implements Source {
	const IDENTIFIER = 'shopify';

	const CREDENTIALS = [
		'access_token',
		'domain',
	];

	private $access_token, $domain;

	public function __construct( $args ) {
		$this->validate_credentials( $args );

		$this->access_token = $args['access_token'];
		$this->domain = $args['domain'];
	}

	public function validate_credentials( $args ) {
		if ( empty( $args['access_token'] ) ) {
			throw new \Exception( 'Access token not set.' );
		}

		if ( empty( $args['domain'] ) ) {
			throw new \Exception( 'Store domain not set.' );
		}
	}

	public function get_products() {
		return [
			[
				'id' => 1,
				'title' => 'Product 1',
				'price' => 10,
				'slug'  => 'product-1',
			],
			[
				'id' => 2,
				'title' => 'Product 2',
				'price' => 20,
				'slug'  => 'product-2',
			],
			[
				'id' => 3,
				'title' => 'Product 3',
				'price' => 30,
				'slug'  => 'product-3',
			],
		];
	}

	public function get_orders() {
		return [];
	}
}
