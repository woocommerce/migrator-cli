<?php

namespace Migrator;

class Workers {
	private $workers = [];

	public function register_worker( $args ) {

		$args = wp_parse_args(
			$args,
			[
				'title'         => '',
				'migrator'      => '',
				'message'       => '',
				'data_callback' => null,
			]
		);

		if ( ! $args['identifier'] && ! is_string( $args['identifier'] ) ) {
			throw new \Exception( '$identifier must be presented' );
		}

		if ( ! $args['migrator'] && ! is_string( $args['migrator'] ) ) {
			throw new \Exception( '$migrator must be presented' );
		}

		if ( ! is_null( $args['data_callback'] ) && ! is_callable( $args['data_callback'] ) ) {
			throw new \Exception( '$data_callback must be a callable function.' );
		}

		$this->workers[ $args['migrator'] ][ $args['identifier'] ] = $args['data_callback'];
	}

	public function get_workers( $migrator ) {
		$workers = $this->workers[ $migrator ] ?? false;

		if ( ! $workers ) {
			throw new \Exception( 'No workers found for migrator: ' . $migrator );
		}

		return $workers;
	}
}