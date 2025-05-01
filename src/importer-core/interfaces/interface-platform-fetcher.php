<?php
/**
 * Interface for platform-specific data fetchers.
 *
 * Defines the contract for classes responsible for retrieving
 * data (like products or orders) from a source platform API.
 */
interface Platform_Fetcher_Interface {

	/**
	 * Fetches a batch of items from the source platform.
	 *
	 * @param array $args Arguments for fetching (e.g., limit, cursor, filters).
	 *                    Specific arguments depend on the implementation.
	 * @return array An array containing:
	 *               'items' => array Raw items fetched from the platform.
	 *               'cursor' => ?string The cursor for the next page, or null if no more pages.
	 *               'hasNextPage' => bool Indicates if there are more pages to fetch.
	 */
	public function fetch_batch( array $args ): array;

	/**
	 * Fetches the estimated total count of items available for migration.
	 *
	 * Used primarily for progress indicators. Returning null is acceptable
	 * if the platform API doesn't easily provide a total count.
	 *
	 * @param array $args Arguments for filtering the count (e.g., status, date range).
	 *                    Specific arguments depend on the implementation.
	 * @return ?int The total estimated count, or null.
	 */
	public function fetch_total_count( array $args ): ?int;

} 