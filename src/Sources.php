<?php

namespace Migrator;

use Migrator\Interfaces\Source;
use Migrator\Sources\Shopify;

class Sources {
	private $container;

	private $sources = [];

	private $credentials = [];

	public function __construct( $container ) {
		$this->container = $container;
		$this->register( Shopify::class );
	}

	public function register( $class ) {
		if ( ! is_subclass_of( $class, Source::class ) ) {
			throw new \Exception( 'Class must implement the Source interface.' );
		}

		$this->sources[ $class::IDENTIFIER ] = $class;
		$this->credentials[ $class::IDENTIFIER ] = $class::CREDENTIALS;
		$this->container->register( $class, $this->container->factory( function( $container, $parameters ) use ( $class ) {
			return new $class( $parameters );
		} ) );
	}

	public function get_source( $args ) {
		$source = false;

		if ( ! empty( $args['source'] ) ) {
			try {
				$source = $this->container->get( $this->sources[ $args['source'] ], $args );
			} catch ( \Exception $e ) {
				$source = false;
			}
		}

		if ( $source ) {
			return $source;
		}

		if ( empty( $args['source'] ) ) {
			$source_config = $this->get_default_source_config();
		} else {
			$source_config = $this->detect_source_config( $args['source'], $args );
		}

		$source = $this->container->get( $this->sources[ $source_config['identifier'] ], $source_config['credentials'] );

		if ( ! $source ) {
			throw new \Exception( 'Source not found.' );
		}

		return $source;
	}

	private function detect_source_config( $source, $args ) {
		$enable_sources_config = $this->get_enabled_sources_config();

		$source_config = array_filter( $enable_sources_config, function( $source_config ) use ( $source ) {
			return $source_config['identifier'] === $source;
		} );

		if ( empty( $source_config ) ) {
			return false;
		}

		$searches = array_intersect_key( $args, $this->credentials[ $source ] );

		$source_config = array_filter( $source_config, function( $source_config ) use ( $searches ) {
			return empty( array_diff_assoc( $searches, $source_config['credentials'] ) );
		} );

		if ( empty( $source_config ) ) {
			return false;
		}

		return current( $source_config );
	}

	private function get_default_source_config() {
		return current( $this->get_enabled_sources_config() );
	}

	/**
	 * Each source configuration is an array with the following keys:
	 * - identifier: The source type identifier.
	 * - credentials: The source configuration.
	 * - enabled: Whether this source is enabled.
	 * 
	 * @return array Array of source configurations.
	 */
	private function get_available_sources_config() {
		$config = [];
		/**
		 * If the config file exists, use it.
		 */
		if ( file_exists( __DIR__ . '/../config.php' ) ) {
			$config = include __DIR__ . '/../config.php';
		}

		/**
		 * Lastly, try to get the sources from the options table. This also
		 * allows us to filter the sources using the `pre_option_migrator_sources` filter.
		 */
		if ( empty( $config ) ) {
			$config = get_option( 'migrator_sources', [] );
		}

		if ( empty( $config ) ) {
			throw new \Exception( 'No sources available.' );
		}

		return $config;
	}

	private function get_enabled_sources_config() {
		$available_sources_config = $this->get_available_sources_config();

		$enable_sources = array_filter( $available_sources_config, function( $source ) {
			return ! empty( $source['enabled'] );
		} );

		if ( empty( $enable_sources ) ) {
			throw new \Exception( 'No sources enabled.' );
		}

		return $enable_sources;
	}
}