<?php
namespace Migrator;

use Migrator\Migrators\Product;
use Migrator\Registry\Container;

class Main {

	public function init() {
		if ( ! class_exists( 'WooCommerce', false ) ) {
			return;
		}

		$this->register_dependencies();

		add_action( 'cli_init', function() {
			\WP_CLI::add_command( 'migrator', 'Migrator\\CLI' );
		} );
	}

	private function register_dependencies() {
		$container = self::container( true );

		// Passing a container instance to the Sources class to manage sources.
		$container->register( Sources::class, function() {
			return new Sources( new Container() );
		} );

		$container->register( Workers::class, function() {
			return new Workers();
		} );

		$container->register(
			Migrators\Product::class,
			$container->factory( function( Container $container, $parameters ) {
				return new Product(
					$container->get( Sources::class )->get_source( $parameters ),
					$container->get( Workers::class )->get_workers( 'product' )
				);
			} )
		);
	}

	/**
	 * Loads the dependency injection container.
	 *
	 * @param boolean $reset Used to reset the container to a fresh instance.
	 *                       Note: this means all dependencies will be
	 *                       reconstructed.
	 */
	public static function container( $reset = false ) {
		static $container;
		if (
			! $container instanceof Container
			|| $reset
		) {
			$container = new Container();
		}
		return $container;
	}
}