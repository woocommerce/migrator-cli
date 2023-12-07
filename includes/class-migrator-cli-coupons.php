<?php

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

class Migrator_CLI_Coupons {

	/**
	 * Imports the coupons/discounts from Shopify into Woo.
	 * Not all types of discounts are supported yet.
	 */
	public function import( $assoc_args ) {
		$imported = 0;
		$limit    = isset( $assoc_args['limit'] ) ? $assoc_args['limit'] : 1000;
		$cursor   = isset( $assoc_args['cursor'] ) ? $assoc_args['cursor'] : '';

		do {
			$response_data = $this->get_next_discount( $cursor );
			$discount      = reset( $response_data->data->codeDiscountNodes->edges );
			$cursor        = $discount->cursor;
			$discount      = $discount->node->codeDiscount;

			if ( $discount ) {
				$this->create_or_update_coupon( $discount );
			}

			WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Cursor: ' . $cursor );

			// Prevents api throttling.
			sleep( 1 );
			++$imported;
		} while ( $response_data->data->codeDiscountNodes->pageInfo->hasNextPage && $imported < $limit );

		WP_CLI::line( WP_CLI::colorize( '%GDone%n' ) );
	}

	private function get_next_discount( $cursor ) {
		if ( $cursor ) {
			$cursor = 'after: "' . $cursor . '"';
		}

		$response = Migrator_CLI_Utils::graphql_request(
			array(
				'query' => '{
						codeDiscountNodes(
							first: 1
							reverse: true
							' . $cursor . '
						) {
							edges {
								cursor
								node {
									id
									codeDiscount {
										... on DiscountCodeFreeShipping {
											appliesOnOneTimePurchase
											appliesOnSubscription
											appliesOncePerCustomer
											asyncUsageCount
											codeCount
											codes(first: 100) {
												nodes {
													code
													id
												}
											}
											combinesWith {
												orderDiscounts
												productDiscounts
												shippingDiscounts
											}
											createdAt
											customerSelection {
												... on DiscountCustomers {
													customers {
														email
													}
												}
												... on DiscountCustomerSegments {
													segments {
														id
														name
													}
												}
												... on DiscountCustomerAll {
													allCustomers
												}
											}
											destinationSelection {
												... on DiscountCountryAll {
													allCountries
												}
												... on DiscountCountries {
													countries
													includeRestOfWorld
												}
											}
											discountClass
											endsAt
											startsAt
											maximumShippingPrice {
												amount
											}
											minimumRequirement {
												... on DiscountMinimumSubtotal {
													greaterThanOrEqualToSubtotal {
														amount
													}
												}
												... on DiscountMinimumQuantity {
													greaterThanOrEqualToQuantity
												}
											}
											recurringCycleLimit
											summary
											title
										}
										... on DiscountCodeBasic {
											appliesOncePerCustomer
											asyncUsageCount
											createdAt
											discountClass
											endsAt
											hasTimelineComment
											minimumRequirement {
												... on DiscountMinimumSubtotal {
													greaterThanOrEqualToSubtotal {
														amount
														currencyCode
													}
												}
												... on DiscountMinimumQuantity {
													greaterThanOrEqualToQuantity
												}
											}
											recurringCycleLimit
											shortSummary
											startsAt
											status
											summary
											title
											updatedAt
											usageLimit
											codes(first: 100) {
												nodes {
													asyncUsageCount
													code
													id
												}
											}
											combinesWith {
												orderDiscounts
												productDiscounts
												shippingDiscounts
											}
											customerGets {
												appliesOnOneTimePurchase
												appliesOnSubscription
												value {
													... on DiscountPercentage {
														percentage
													}
													... on DiscountOnQuantity {
														effect {
															... on DiscountPercentage {
																percentage
															}
														}
														quantity {
															quantity
														}
													}
													... on DiscountAmount {
														appliesOnEachItem
														amount {
															amount
															currencyCode
														}
													}
												}
												items {
													... on DiscountProducts {
														products(first: 100) {
															nodes {
																id
																legacyResourceId
															}
														}
													}
													... on AllDiscountItems {
														allItems
													}
													... on DiscountCollections {
														collections(first: 100) {
															nodes {
																handle
															}
														}
													}
												}
											}
											customerSelection {
												... on DiscountCustomerAll {
													allCustomers
												}
												... on DiscountCustomers {
													customers {
														email
														id
													}
												}
												... on DiscountCustomerSegments {
													segments {
														id
														name
													}
												}
											}
										}
									}
								}
							}
							pageInfo {
								endCursor
								hasNextPage
								hasPreviousPage
								startCursor
							}
						}
					}',
			)
		);

		$response_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $response_data->data->codeDiscountNodes ) ) {
			WP_CLI::error( 'No coupons found.' );
		}

		return $response_data;
	}

	/**
	 * Creates or updates a coupon.
	 *
	 * @param object $discount the shopify discount data.
	 * @param object $child_code the child coupon code.
	 */
	private function create_or_update_coupon( $discount, $child_code = null ) {
		WP_CLI::line( '=========================================================================' );

		if ( $child_code ) {
			$coupon_code = $child_code->code;
			$usage       = $child_code->asyncUsageCount;
		} else {
			$coupon_code = $discount->title;
			$usage       = $discount->asyncUsageCount;

			if ( isset( $discount->codes->nodes[0] ) ) {
				$coupon_code = $discount->codes->nodes[0]->code;
				unset( $discount->codes->nodes[0] );
			}
		}

		WP_CLI::line( WP_CLI::colorize( '%BInfo:%n ' ) . 'Processing coupon: ' . $coupon_code );
		$coupon = new WC_Coupon( $coupon_code );

		if ( 0 === $coupon->get_id() ) {
			WP_CLI::line( "Coupon didn't exist yet creating a new one" );
		} else {
			WP_CLI::line( 'Coupon already exists updating' );
		}

		$this->cleanup( $coupon );

		$coupon->set_code( $coupon_code );
		$coupon->set_description( $discount->summary );
		$coupon->set_date_created( $discount->createdAt );
		$coupon->set_date_expires( $discount->endsAt );
		$coupon->set_date_modified( $discount->updatedAt );
		$coupon->set_usage_limit( $discount->usageLimit );
		$coupon->set_usage_count( $usage );
		$coupon->set_usage_limit_per_user( true === $discount->appliesOncePerCustomer ? 1 : null );

		$this->check_unsupported_rules( $discount );
		$this->set_restrictions( $coupon, $discount );
		$this->set_limits( $coupon, $discount );
		$this->set_discount_type( $coupon, $discount );

		if ( 'SHIPPING' === $discount->discountClass ) {
			$coupon->set_free_shipping( 1 );
		}

		$coupon->save();

		if ( ! $child_code ) {
			foreach ( $discount->codes->nodes as $code ) {
				$this->create_or_update_coupon( $discount, $code );
			}

			if ( count( $discount->codes->nodes ) >= 99 ) {
				WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'Only the first 100 codes were processed for this coupon.' );
			}
		}
	}

	/**
	 * Cleans up a coupon before it can be updated
	 * to prevent the wrong data from being saved.
	 *
	 * @param WC_Coupon $coupon the Woo coupon.
	 */
	private function cleanup( $coupon ) {
		$coupon->set_minimum_amount( null );
		$coupon->set_email_restrictions( null );
		$coupon->set_free_shipping( null );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_individual_use( true );
	}

	/**
	 * Checks rules that Woo does not support.
	 *
	 * @param object $discount the shopify discount data.
	 */
	private function check_unsupported_rules( $discount ) {

		if ( new DateTime( $discount->startsAt ) > ( new DateTime( 'now' ) ) ) {
			WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Woo does not support coupons with a start date in the future.' );
		}

		if ( isset( $discount->minimumRequirement->greaterThanOrEqualToQuantity ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'Minimum product quantity not supported by Woo.' );
		}

		if ( isset( $discount->customerSelection->segments ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'Coupon customer segmentation not supported by Woo.' );
		}

		if ( true === $discount->combinesWith->orderDiscounts || true === $discount->combinesWith->productDiscounts || true === $discount->combinesWith->shippingDiscounts ) {
			WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'The importer does handle combining discounts at the moment.' );
		}

		if ( 'SHIPPING' === $discount->discountClass ) {
			if ( ! isset( $discount->destinationSelection->allCountries ) || true !== $discount->destinationSelection->allCountries ) {
				WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'Woo does not support free shipping coupons for specific countries only. This coupon will apply to all countries.' );
			}

			if ( null !== $discount->maximumShippingPrice ) {
				WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'Woo does not support limiting free shipping for a maximum shipping value.' );
			}
		}
	}

	/**
	 * Sets the coupon restrictions like minimum total,
	 * Products that the coupon applies.
	 *
	 * @param WC_Coupon $coupon the Woo coupon.
	 * @param object $discount the Shopify discount data.
	 */
	private function set_restrictions( $coupon, $discount ) {
		// Minimum total
		if ( isset( $discount->minimumRequirement->greaterThanOrEqualToSubtotal ) ) {
			$coupon->set_minimum_amount( $discount->minimumRequirement->greaterThanOrEqualToSubtotal->amount );
		}

		// Products
		if ( isset( $discount->customerGets->items ) && true !== $discount->customerGets->items->allItems ) {
			$meta_values = array();

			foreach ( $discount->customerGets->items->products->nodes as $shopify_product ) {
				$meta_values[] = $shopify_product->legacyResourceId;
			}

			add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( $this, 'handle_custom_query_var' ), 10, 2 );

			$products = wc_get_products(
				array(
					'limit'                => -1,
					'_original_product_id' => $meta_values,
				)
			);

			$product_ids = array();
			foreach ( $products as $product ) {
				$product_ids[] = $product->get_id();
			}

			$coupon->set_product_ids( $product_ids );
		}
	}

	/**
	 * Adds a customer query var to search for products with one of the shopify products ids.
	 *
	 * @param array $query the query.
	 * @param array $query_vars the query vars.
	 * @return array
	 */
	public function handle_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['_original_product_id'] ) ) {
			$query['meta_query'][] = array(
				'key'     => '_original_product_id',
				'value'   => $query_vars['_original_product_id'],
				'compare' => 'IN',
			);
		}

		return $query;
	}


	/**
	 * Sets coupons limits like user emails and maximum number of cycles.
	 *
	 * @param WC_Coupon $coupon the Woo coupon.
	 * @param object $discount the shopify discount data.
	 */
	private function set_limits( $coupon, $discount ) {
		// Limited to specific user emails
		if ( isset( $discount->customerSelection->customers ) ) {
			$emails = array();
			foreach ( $discount->customerSelection->customers as $customer ) {
				$emails[] = $customer->email;
			}

			$coupon->set_email_restrictions( $emails );
		}

		// Cycle limits, key from https://github.com/woocommerce/woocommerce-subscriptions/blob/b7e2a57ab730ab8a146b7a12e55efefa7f6fee1d/includes/class-wcs-limited-recurring-coupon-manager.php#L18
		$coupon->update_meta_data( '_wcs_number_payments', $discount->recurringCycleLimit );
	}

	/**
	 * Sets the discount type. Shopify discount types are not a perfect match
	 * so they need to be loosely converted.
	 * When a discount is set to be used in both subscriptions and one time purchases
	 * converts them to subscriptions only.
	 *
	 * @param WC_Coupon $coupon the Woo coupon.
	 * @param object $discount the Shopify discount data.
	 */
	private function set_discount_type( WC_Coupon $coupon, $discount ) {
		$needs_limit = $discount->recurringCycleLimit && true === $discount->customerGets->appliesOnSubscription;
		$can_limit   = true;

		// Percent discount.
		if ( isset( $discount->customerGets->value->percentage ) ) {
			$coupon->set_amount( $discount->customerGets->value->percentage * 100 );
			$coupon->set_discount_type( 'percent' );
			$can_limit = false;

			if ( true === $discount->customerGets->appliesOnSubscription ) {
				$coupon->set_discount_type( 'recurring_percent' );
				$needs_limit = false;
			}
		}

		// Fixed amount.
		if ( isset( $discount->customerGets->value->amount ) ) {
			$coupon->set_amount( $discount->customerGets->value->amount->amount );

			$coupon->set_discount_type( 'fixed_cart' );
			$can_limit = false;

			if ( true === $discount->customerGets->value->appliesOnEachItem ) {
				$coupon->set_discount_type( 'fixed_product' );
			}

			if ( true === $discount->customerGets->appliesOnSubscription ) {
				$coupon->set_discount_type( 'recurring_fee' );
				$needs_limit = false;
			}
		}

		// Either recurring_fee or recurring_percent would work when there is a recurringCycleLimit
		if ( $needs_limit && $can_limit ) {
			$coupon->set_discount_type( 'recurring_fee' );
		} elseif ( $needs_limit && ! $can_limit ) {
			WP_CLI::line( WP_CLI::colorize( '%RError:%n ' ) . 'Can`t limit a coupon usage to a single cycle when it`s not a subscription coupon' );
		}

		/**
		 * When both appliesOnOneTimePurchase and appliesOnSubscription
		 * are true Shopify coupons can be applied to both one time purchases and subscriptions
		 * but Woo only supports one or the other.
		 * As coupons are used to renewal subscriptions we set them as subscription coupons
		 * if appliesOnSubscription is set to true.
		 * This will cause problems for people that try to use those coupons for one time
		 * purchases after the migration.
		 */
		if ( true === $discount->customerGets->appliesOnSubscription && true === $discount->customerGets->appliesOnOneTimePurchase ) {
			WP_CLI::line( WP_CLI::colorize( '%YWarning:%n ' ) . 'This coupon is set to be used both in one time purchases and subscriptions in Shopify but Woo does not support that. Setting it up as a subscription coupon.' );
		}
	}
}

// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
