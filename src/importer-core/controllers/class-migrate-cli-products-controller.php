<?php

require_once __DIR__ . '/../interfaces/interface-platform-fetcher.php';
require_once __DIR__ . '/../interfaces/interface-platform-mapper.php';
require_once __DIR__ . '/../../platforms/shopify/class-shopify-fetcher.php';
require_once __DIR__ . '/../../platforms/shopify/class-shopify-mapper.php';
require_once __DIR__ . '/../importers/class-woocommerce-product-importer.php';

class Migrate_CLI_Products {

	private $fields;
	private $assoc_args;
	private $saved_filters; // Added for disable/restore hooks

	private $verbose;

	/**
	 * Main entry point for migrating products.
	 *
	 * @param array $assoc_args Command-line arguments
	 */
	public function migrate_products( $assoc_args ) {
		Migrate_CLI_Utils::health_check();

		// --- Platform Registration ---
		$available_platforms = apply_filters(
			'migrate_cli_available_platforms',
			[
				'shopify' => [
					'fetcher' => 'Shopify_Fetcher',
					'mapper'  => 'Shopify_Mapper',
					'label'   => 'Shopify',
				],
				// Other platforms can be registered here via the filter
			]
		);

		// --- Argument Parsing & Validation ---
		$args = $this->parse_and_validate_args( $assoc_args, $available_platforms );
		if ( ! $args ) {
			return; // Error handled in parse_and_validate_args
		}

		// --- Disable Hooks ---
		if ( $args->disable_hooks ) {
			$this->disable_hooks();
		}

		// --- Instantiate Components ---
		$platform_key = $args->platform; // e.g., 'shopify'
		$fetcher_class = $available_platforms[$platform_key]['fetcher'] ?? null;
		$mapper_class  = $available_platforms[$platform_key]['mapper'] ?? null;

		if ( ! $fetcher_class || ! class_exists( $fetcher_class ) || ! $mapper_class || ! class_exists( $mapper_class ) ) {
			WP_CLI::error( sprintf( "Invalid Fetcher ('%s') or Mapper ('%s') class registered for platform '%s'.", $fetcher_class, $mapper_class, $platform_key ) );
			$this->maybe_restore_hooks( $args->disable_hooks );
			return;
		}

		// Pass necessary args to constructors (e.g., fields for mapper, verbose flag)
		$mapper_args = ['fields' => $this->fields]; // Pass fields from $this->fields
		$importer_args = ['verbose' => $this->verbose];

		$fetcher = new $fetcher_class();
		$mapper = new $mapper_class( $mapper_args );
		$importer = new WooCommerce_Product_Importer( $importer_args );

		// --- Fetch Total Count ---
		$count_args = []; // Pass relevant filters from $args if needed by fetch_total_count
		if ( isset( $args->query_filter_parts['status'] ) ) $count_args['status'] = $args->query_filter_parts['status'];
		if ( isset( $args->query_filter_parts['before'] ) ) $count_args['before'] = $args->query_filter_parts['before'];
		if ( isset( $args->query_filter_parts['after'] ) )  $count_args['after'] = $args->query_filter_parts['after'];
		if ( isset( $args->target_product_ids ) ) $count_args['ids'] = implode( ',', $args->target_product_ids ); // Pass IDs if set
		
		$total_count = $fetcher->fetch_total_count( $count_args );
		WP_CLI::line( 'Total entities found: ' . $total_count );
		if ( null === $total_count ) {
			WP_CLI::warning( 'Could not retrieve total count. Progress bar may be inaccurate.' );
			$total_count = 0; // Set to 0 for progress bar if unknown
		}
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing Products from ' . $available_platforms[$platform_key]['label'], $total_count );

		// --- Main Import Loop ---
		$overall_start_time    = microtime( true );
		$total_processed_count = 0; // Controller loop counter
		$limit_remaining       = $args->limit;
		$after_cursor          = $args->after_cursor;
		$has_next_page         = true;

		do {
			$batch_start_time = microtime( true );
			$batch_limit = min( $args->perpage, $limit_remaining );
			if ( $batch_limit <= 0 ) {
				break; // Limit reached
			}

			// 1. Fetch a batch using the Fetcher
			$fetch_args = [
					'limit' => $batch_limit,
					'after_cursor' => $after_cursor,
				'query_filter' => $args->query_filter, // Pass the combined query string
				'variants_per_product' => $args->variants_per_product,
				// Pass other platform-specific args if needed
			];
			if ( $this->verbose ) WP_CLI::line( sprintf( 'Fetching next %d products... (Cursor: %s)', $batch_limit, $after_cursor ?? 'start' ) );
			$batch_data = $fetcher->fetch_batch( $fetch_args );

			if ( empty( $batch_data['items'] ) ) {
				WP_CLI::line( 'No more products found in this batch.' );
				$has_next_page = false; // Explicitly set based on empty items
				break;
			}

			if ( $this->verbose ) WP_CLI::line( sprintf( 'Fetched %d products.', count( $batch_data['items'] ) ) );

			// 2. Process the fetched batch
			// Pass fetcher/mapper/importer instances to batch processing
			$batch_result = $this->process_product_batch(
				$batch_data['items'], // Pass raw items
				$args,                // Pass command args
				$progress,
				$mapper,              // Pass instantiated mapper
				$importer             // Pass instantiated importer
			);

			$batch_processed_count = $batch_result['processed_count'];
			$total_processed_count += $batch_processed_count; // Increment controller counter
			// Decrement limit by items *fetched* in the batch, not just processed ones,
			// to ensure we respect the overall limit even if some items are skipped.
			$limit_remaining -= count( $batch_data['items'] ); 
			$after_cursor = $batch_data['cursor']; // Get cursor from fetcher response
			$has_next_page = $batch_data['hasNextPage']; // Get hasNextPage from fetcher response

			if ( $this->verbose ) {
				WP_CLI::line( sprintf( 'Batch processed %d products (controller loop) in %.2f seconds.', $batch_processed_count, microtime( true ) - $batch_start_time ) );
				WP_CLI::line( 'Next Cursor: ' . ( $after_cursor ?? 'None' ) . ' | Has Next Page: ' . ( $has_next_page ? 'Yes' : 'No' ) );
				WP_CLI::line( '' );
			}

			// Clear cache if continuing
			if ( $has_next_page && $limit_remaining > 0 ) {
				Migrate_CLI_Utils::reset_in_memory_cache();
			}

		} while ( $has_next_page && $limit_remaining > 0 );

		// --- Finalize ---
		$progress->finish();
		$this->print_summary( $total_processed_count, $overall_start_time, $importer ); // Pass importer for summary
		$this->maybe_restore_hooks( $args->disable_hooks );
	}

	/**
	 * Parses and validates command-line arguments.
	 *
	 * @param array $assoc_args Raw arguments.
	 * @param array $available_platforms Registered platforms from filter.
	 * @return object|false Parsed arguments object or false on error.
	 */
	private function parse_and_validate_args( $assoc_args, $available_platforms ) {
		$this->assoc_args = $assoc_args; // Store for use in should_process

		// --- Field Selection ---
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

		// --- Basic Arguments ---
		$args = new stdClass();
		$args->limit              = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : PHP_INT_MAX;
		$args->perpage            = isset( $assoc_args['perpage'] ) ? min( (int) $assoc_args['perpage'], 250 ) : 100;
		$args->skip_update        = isset( $assoc_args['skip-update'] );
		$args->exclude_ids        = isset( $assoc_args['exclude'] ) ? explode( ',', $assoc_args['exclude'] ) : array();
		$args->after_cursor    	  = isset( $assoc_args['next'] ) ? $assoc_args['next'] : null;
		$args->target_product_ids = isset( $assoc_args['ids'] ) ? explode( ',', $assoc_args['ids'] ) : null;
		$this->verbose            = isset( $assoc_args['verbose'] );
		$args->disable_hooks      = isset( $assoc_args['disable-hooks'] );
		$args->remove_orphans     = isset( $assoc_args['remove-orphans'] ); // Keep for importer potentially

		// --- Variants Per Product ---
		$variants_per_product_default = 100;
		$args->variants_per_product = isset( $assoc_args['variants-per-product'] ) ? (int) $assoc_args['variants-per-product'] : $variants_per_product_default;
		if ( $args->variants_per_product < 1 || $args->variants_per_product > 2000 ) {
			WP_CLI::warning( 'Invalid value for --variants-per-product. Must be between 1 and 2000. Using default: ' . $variants_per_product_default );
			$args->variants_per_product = $variants_per_product_default;
		} elseif ( $args->variants_per_product !== $variants_per_product_default ) {
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Fetching ' . $args->variants_per_product . ' variants per product.' );
		}

		// --- Platform Selection ---
		$args->platform = $assoc_args['platform'] ?? 'shopify'; // Default to shopify
		if ( ! isset( $available_platforms[ $args->platform ] ) ) {
			$valid_platforms = implode( ', ', array_keys( $available_platforms ) );
			WP_CLI::error( sprintf( "Invalid platform specified: '%s'. Please use one of: %s", $args->platform, $valid_platforms ) );
			return false;
		}
		WP_CLI::line( 'Selected platform: ' . $args->platform );

		// --- Build GraphQL query filter string (Shopify specific, may need abstraction later) ---
		$query_parts = [];
		$args->query_filter_parts = []; // Store parts for potential use elsewhere (like fetch_total_count)
		if ( isset( $assoc_args['status'] ) ) {
			$status = strtoupper( $assoc_args['status'] );
			$query_parts[] = 'status:' . $status;
			$args->query_filter_parts['status'] = $status;
		}
		if ( isset( $assoc_args['handle'] ) ) {
			$query_parts[] = 'handle:' . $assoc_args['handle'];
			$args->query_filter_parts['handle'] = $assoc_args['handle'];
		}
		if ( isset( $assoc_args['product-type'] ) && 'all' !== $assoc_args['product-type'] ) {
			$product_type = $assoc_args['product-type'];
			$query_parts[] = 'product_type:"' . $product_type . '"';
			$args->query_filter_parts['product_type'] = $product_type;
		}
		if ( isset( $assoc_args['before'] ) ) {
			$before_date = $assoc_args['before'];
			$query_parts[] = 'created_at:<=' . $before_date;
			$args->query_filter_parts['before'] = $before_date;
		}
		if ( isset( $assoc_args['after'] ) ) {
			$after_date = $assoc_args['after'];
			$query_parts[] = 'created_at:>=' . $after_date;
			$args->query_filter_parts['after'] = $after_date;
		}
		$args->query_filter = implode( ' AND ', $query_parts );

		WP_CLI::line( 'Starting product migration using GraphQL...' );
		if ( $args->query_filter ) {
			WP_CLI::line( 'Using query filter: ' . $args->query_filter );
		}
		WP_CLI::line( '' );

		return $args;
	}

	/**
	 * Processes a batch of fetched platform products.
	 *
	 * @param array  $platform_items Raw items array from the Fetcher.
	 * @param object $args           Parsed command arguments.
	 * @param \WP_CLI\Utils\ProgressBar $progress Progress bar instance.
	 * @param Platform_Mapper_Interface $mapper The instantiated Mapper.
	 * @param WooCommerce_Product_Importer $importer The instantiated Importer.
	 * @return array Result containing processed count.
	 */
	private function process_product_batch( $platform_items, $args, $progress, $mapper, $importer ) {
		$processed_count_in_batch = 0;

		foreach ( $platform_items as $item ) { // $item could be edge or node depending on Fetcher
			// Assuming Fetcher returns edges containing nodes for Shopify
			$platform_product = $item->node ?? $item; // Adapt if Fetcher returns nodes directly

			if ( ! is_object( $platform_product ) ) {
				WP_CLI::warning( 'Skipping invalid item in batch.' );
				continue;
			}

			$process_result = $this->process_single_product(
				$platform_product,
				$args,
				$progress,
				$mapper,
				$importer
			);

			if ( $process_result['processed'] ) {
				$processed_count_in_batch++;
			}
		}

		return [
			'processed_count' => $processed_count_in_batch,
		];
	}

	/**
	 * Processes a single platform product.
	 *
	 * @param object $platform_product Platform product node from Fetcher.
	 * @param object $args Parsed command arguments.
	 * @param \WP_CLI\Utils\ProgressBar $progress Progress bar instance.
	 * @return array Result indicating if processed.
	 */
	private function process_single_product( $platform_product, $args, $progress, $mapper, $importer ) {
		// --- Identify Product --- (Adapt based on platform)
		$original_product_id = null;
		$product_handle = 'Unknown';
		if (property_exists($platform_product, 'id')) {
			$original_product_id = basename( $platform_product->id ); // Shopify specific GQL ID parsing
		}
		if (property_exists($platform_product, 'handle')) {
			$product_handle = $platform_product->handle;
		} elseif ($original_product_id) {
			$product_handle = $original_product_id;
		}

		$processed = false;
		$tick_progress = true; // Tick progress by default unless explicitly excluded

		if ( ! $original_product_id ) {
			WP_CLI::warning( 'Skipping item - could not determine original product ID.' );
			$tick_progress = false; // Don't tick for invalid items
			return ['processed' => false];
		}

			if ( $this->verbose ) {
			WP_CLI::line( sprintf( 'Processing product %s (Original ID: %s)... ', $product_handle, $original_product_id ) );
		}
		$product_start_time = microtime( true );

		// --- Filtering ---
		if ( isset( $args->target_product_ids ) && ! in_array( $original_product_id, $args->target_product_ids, true ) ) {
			if ( $this->verbose ) WP_CLI::line( sprintf( ' - Skipping: Not in target IDs.' ) );
			// Tick progress as it was counted in total
		} elseif ( in_array( $original_product_id, $args->exclude_ids, true ) ) {
			if ( $this->verbose ) WP_CLI::line( sprintf( ' - Skipping: Excluded by ID.' ) );
			$tick_progress = false; // Excluded items were not counted in total
		} else {
			// --- Mapping & Importing ---
			try {
				$wc_data = $mapper->map_product_data( $platform_product );

				if ( $args->skip_update ) {
					WP_CLI::warning("Importer::find_existing_product_id() needs implementation for skip-update.");
					// $existing_product_id = $importer->find_existing_product_id( $wc_data ); // Needs method in Importer
					$existing_product_id = null; // Placeholder
					if ( $existing_product_id ) {
						if ( $this->verbose ) WP_CLI::line( sprintf( ' - Skipping: Product %s already exists (WC ID: %d) and --skip-update is set.', $product_handle, $existing_product_id ) );
						$tick_progress = true; // Still tick progress
						return ['processed' => false]; // Return here to skip import
					}
				}

				$import_result = $importer->import_product( $wc_data );

				if ( is_wp_error( $import_result ) ) {
					WP_CLI::warning( sprintf( ' - Failed importing product %s (Original ID: %s). Error: %s', $product_handle, $original_product_id, $import_result->get_error_message() ) );
					$tick_progress = false; // Don't tick progress on import error
		} else {
					if ( $this->verbose ) WP_CLI::line( sprintf( ' - Successfully processed product %s -> WC ID: %d', $product_handle, $import_result ) );
				$processed = true;
					$tick_progress = true;
				}
			} catch ( Exception $e ) {
				WP_CLI::warning( sprintf( ' - Exception during processing product %s (Original ID: %s). Error: %s', $product_handle, $original_product_id, $e->getMessage() ) );
				$tick_progress = false; // Don't tick progress on exception
			}
		}

		$product_duration = microtime( true ) - $product_start_time;
		if ( $this->verbose ) {
			WP_CLI::line( sprintf( 'Product %s finished in %.2f seconds.', $product_handle, $product_duration ) );
		}

		if ( $tick_progress ) {
			$progress->tick();
		}

		return ['processed' => $processed];
	}

	/**
	 * Prints the final migration summary.
	 *
	 * @param int $processed_count Total products processed.
	 * @param float $overall_start_time Timestamp when the migration started.
	 */
	private function print_summary( $processed_count, $overall_start_time, $importer ) {
		$overall_end_time = microtime( true );
		$total_duration = $overall_end_time - $overall_start_time;

		WP_CLI::line( '---------------------------------' );
		WP_CLI::success( sprintf( 'Finished migrating products. Controller processed: %d items through the loop.', $processed_count ) );
		WP_CLI::line( sprintf( 'Total migration time: %.2f seconds.', $total_duration ) );

		if ( $processed_count > 0 ) {
			$average_duration = $total_duration / $processed_count;
			WP_CLI::line( sprintf( 'Average time per item (controller loop): %.2f seconds.', $average_duration ) );
		} else {
			WP_CLI::line( 'No items processed by controller loop to calculate average time.' );
		}

		// Add summary from the importer
		$importer_summary = $importer->get_summary();
		WP_CLI::line( sprintf( 'Importer - Created/Updated: %d', $importer_summary['processed'] ?? 0 ) );
		WP_CLI::line( sprintf( 'Importer - Skipped (e.g., --skip-update): %d', $importer_summary['skipped'] ?? 0 ) );
		// Add more details from importer summary if needed (e.g., errors?)

		WP_CLI::line( '---------------------------------' );
	}

	/**
	 * Disables unnecessary WordPress hooks and suspends cache invalidation.
	 */
	private function disable_hooks() {
		global $wp_filter;
		$this->saved_filters = array();
		$hooks_to_disable = array(
			'save_post',
			'wp_insert_post',
			'added_post_meta',
			'updated_post_meta',
			'deleted_post_meta',
			'woocommerce_product_object_updated_props',
			'woocommerce_new_product',
			'woocommerce_update_product',
			'woocommerce_before_product_object_save',
			'woocommerce_after_product_object_save',
		);
		foreach ( $hooks_to_disable as $hook ) {
			if ( isset( $wp_filter[ $hook ] ) ) {
				$this->saved_filters[ $hook ] = $wp_filter[ $hook ];
				remove_all_actions( $hook );
			}
		}
		wp_suspend_cache_invalidation( true );
		WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Disabled hooks and suspended cache invalidation.' );
	}

	/**
	 * Restores WordPress hooks and cache invalidation.
	 */
	private function restore_hooks() {
		wp_suspend_cache_invalidation( false );
		if ( ! empty( $this->saved_filters ) ) {
			global $wp_filter;
			foreach ( $this->saved_filters as $hook => $filter ) {
				$wp_filter[ $hook ] = $filter;
			}
			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Restored hooks and cache invalidation.' );
		}
	}

	/**
	 * Gets the product fields to process.
	 *
	 * @return array the product fields to process.
	 */
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
	 * @param string $subject the subject to check.
	 * @param array $patterns the patterns to check against.
	 * @return bool true if the subject matches any of the patterns, false otherwise.
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
	 * Restores hooks if they were disabled.
	 *
	 * @param bool $were_hooks_disabled Flag indicating if hooks were disabled.
	 */
	private function maybe_restore_hooks( bool $were_hooks_disabled ) {
		if ( $were_hooks_disabled ) {
			$this->restore_hooks();
		}
	}
}
