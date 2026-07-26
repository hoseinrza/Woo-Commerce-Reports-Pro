<?php
/**
 * Data access layer for Sales Analytics reports.
 *
 * Historical days are read from the pre-aggregated snapshot table
 * (built nightly by SA_Cron) so reporting stays fast on stores with a
 * large order history. The current, not-yet-snapshotted day is
 * computed live from WooCommerce orders and merged in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Data {

	/**
	 * Build a full report for a date range, optionally scoped to a category.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param int    $category_id 0 for all categories.
	 * @return array{summary: array, trend: array, top_products: array, categories: array}
	 */
	public static function get_report( $start_date, $end_date, $category_id = 0 ) {
		$rows = self::get_aggregated_rows( $start_date, $end_date, $category_id );

		return array(
			'summary'      => self::build_summary( $rows ),
			'trend'        => self::build_trend( $rows, $start_date, $end_date ),
			'top_products' => self::build_top_products( $rows ),
			'categories'   => self::build_categories( $rows ),
		);
	}

	/**
	 * Return the raw per-day/per-product rows for a range, merging
	 * snapshot data with a live-computed row for today when it falls
	 * inside the range.
	 */
	public static function get_aggregated_rows( $start_date, $end_date, $category_id = 0 ) {
		$today = current_time( 'Y-m-d' );

		$snapshot_end = ( $end_date >= $today ) ? date( 'Y-m-d', strtotime( $today . ' -1 day' ) ) : $end_date;

		$rows = array();

		if ( $start_date <= $snapshot_end ) {
			$rows = array_merge( $rows, self::get_snapshot_rows( $start_date, $snapshot_end, $category_id ) );
		}

		if ( $end_date >= $today && $start_date <= $today ) {
			$rows = array_merge( $rows, self::compute_live_day_rows( $today, $category_id ) );
		}

		return $rows;
	}

	private static function get_snapshot_rows( $start_date, $end_date, $category_id = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . SA_SNAPSHOT_TABLE;

		$sql = "SELECT snapshot_date, product_id, category_ids, orders_count, items_sold, net_revenue, gross_revenue
				FROM {$table}
				WHERE snapshot_date BETWEEN %s AND %s";
		$args = array( $start_date, $end_date );

		if ( $category_id ) {
			$sql   .= ' AND FIND_IN_SET(%d, category_ids)';
			$args[] = $category_id;
		}

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $results ? $results : array();
	}

	/**
	 * Compute rows for a single day directly from WooCommerce orders.
	 * Used for "today" only, so the cost stays bounded to one day's orders.
	 */
	public static function compute_live_day_rows( $date, $category_id = 0 ) {
		$orders = wc_get_orders(
			array(
				'status'       => self::get_reportable_statuses(),
				'date_created' => $date . ' 00:00:00...' . $date . ' 23:59:59',
				'limit'        => -1,
				'return'       => 'objects',
			)
		);

		$rows = array(); // product_id => row

		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product_id = $item->get_product_id();
				if ( ! $product_id ) {
					continue;
				}

				$cat_ids = wc_get_product_term_ids( $product_id, 'product_cat' );

				if ( $category_id && ! in_array( (int) $category_id, $cat_ids, true ) ) {
					continue;
				}

				if ( ! isset( $rows[ $product_id ] ) ) {
					$rows[ $product_id ] = array(
						'snapshot_date' => $date,
						'product_id'    => $product_id,
						'category_ids'  => implode( ',', $cat_ids ),
						'order_ids'     => array(),
						'items_sold'    => 0,
						'net_revenue'   => 0.0,
						'gross_revenue' => 0.0,
					);
				}

				$rows[ $product_id ]['order_ids'][ $order->get_id() ] = true;
				$rows[ $product_id ]['items_sold']                    += $item->get_quantity();
				$rows[ $product_id ]['net_revenue']                   += (float) $item->get_total();
				$rows[ $product_id ]['gross_revenue']                 += (float) $item->get_total() + (float) $item->get_total_tax();
			}
		}

		foreach ( $rows as &$row ) {
			$row['orders_count'] = count( $row['order_ids'] );
			unset( $row['order_ids'] );
		}

		return array_values( $rows );
	}

	/**
	 * Order statuses considered as "sales" for reporting purposes.
	 */
	public static function get_reportable_statuses() {
		return apply_filters( 'sa_reportable_order_statuses', array( 'wc-processing', 'wc-completed' ) );
	}

	private static function build_summary( array $rows ) {
		$total_orders   = array();
		$items_sold     = 0;
		$net_revenue    = 0.0;
		$gross_revenue  = 0.0;

		foreach ( $rows as $row ) {
			$items_sold    += (int) $row['items_sold'];
			$net_revenue   += (float) $row['net_revenue'];
			$gross_revenue += (float) $row['gross_revenue'];
		}

		// Orders count can't simply be summed across products (an order can
		// contain several products), so recompute distinct totals per day.
		$orders_by_day = array();
		foreach ( $rows as $row ) {
			$orders_by_day[ $row['snapshot_date'] ] = isset( $orders_by_day[ $row['snapshot_date'] ] )
				? max( $orders_by_day[ $row['snapshot_date'] ], (int) $row['orders_count'] )
				: (int) $row['orders_count'];
		}
		$total_orders_count = array_sum( $orders_by_day );

		return array(
			'total_revenue'      => round( $net_revenue, 2 ),
			'total_gross_revenue' => round( $gross_revenue, 2 ),
			'total_orders'       => $total_orders_count,
			'items_sold'         => $items_sold,
			'average_order_value' => $total_orders_count ? round( $net_revenue / $total_orders_count, 2 ) : 0,
		);
	}

	private static function build_trend( array $rows, $start_date, $end_date ) {
		$by_day = array();

		$period = new DatePeriod(
			new DateTime( $start_date ),
			new DateInterval( 'P1D' ),
			( new DateTime( $end_date ) )->modify( '+1 day' )
		);

		foreach ( $period as $day ) {
			$by_day[ $day->format( 'Y-m-d' ) ] = array(
				'date'    => $day->format( 'Y-m-d' ),
				'revenue' => 0.0,
				'orders'  => 0,
			);
		}

		$orders_by_day = array();
		foreach ( $rows as $row ) {
			$date = $row['snapshot_date'];
			if ( ! isset( $by_day[ $date ] ) ) {
				continue;
			}

			$by_day[ $date ]['revenue'] += (float) $row['net_revenue'];
			$orders_by_day[ $date ]      = isset( $orders_by_day[ $date ] )
				? max( $orders_by_day[ $date ], (int) $row['orders_count'] )
				: (int) $row['orders_count'];
		}

		foreach ( $orders_by_day as $date => $count ) {
			if ( isset( $by_day[ $date ] ) ) {
				$by_day[ $date ]['orders'] = $count;
			}
		}

		foreach ( $by_day as &$day ) {
			$day['revenue'] = round( $day['revenue'], 2 );
		}

		return array_values( $by_day );
	}

	private static function build_top_products( array $rows, $limit = 20 ) {
		$products = array();

		foreach ( $rows as $row ) {
			$product_id = (int) $row['product_id'];
			if ( ! isset( $products[ $product_id ] ) ) {
				$products[ $product_id ] = array(
					'product_id'   => $product_id,
					'name'         => self::get_product_name( $product_id ),
					'items_sold'   => 0,
					'net_revenue'  => 0.0,
				);
			}

			$products[ $product_id ]['items_sold']  += (int) $row['items_sold'];
			$products[ $product_id ]['net_revenue'] += (float) $row['net_revenue'];
		}

		usort(
			$products,
			function ( $a, $b ) {
				return $b['net_revenue'] <=> $a['net_revenue'];
			}
		);

		$products = array_slice( $products, 0, $limit );

		foreach ( $products as &$product ) {
			$product['net_revenue'] = round( $product['net_revenue'], 2 );
		}

		return $products;
	}

	private static function build_categories( array $rows ) {
		$categories = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['category_ids'] ) ) {
				$cat_ids = array( 0 );
			} else {
				$cat_ids = array_filter( array_map( 'intval', explode( ',', $row['category_ids'] ) ) );
				if ( empty( $cat_ids ) ) {
					$cat_ids = array( 0 );
				}
			}

			foreach ( $cat_ids as $cat_id ) {
				if ( ! isset( $categories[ $cat_id ] ) ) {
					$categories[ $cat_id ] = array(
						'category_id'  => $cat_id,
						'name'         => self::get_category_name( $cat_id ),
						'items_sold'   => 0,
						'net_revenue'  => 0.0,
					);
				}

				$categories[ $cat_id ]['items_sold']  += (int) $row['items_sold'];
				$categories[ $cat_id ]['net_revenue'] += (float) $row['net_revenue'];
			}
		}

		usort(
			$categories,
			function ( $a, $b ) {
				return $b['net_revenue'] <=> $a['net_revenue'];
			}
		);

		foreach ( $categories as &$category ) {
			$category['net_revenue'] = round( $category['net_revenue'], 2 );
		}

		return array_values( $categories );
	}

	public static function get_product_name( $product_id ) {
		$product = wc_get_product( $product_id );
		return $product ? $product->get_name() : sprintf( '#%d', $product_id );
	}

	public static function get_category_name( $category_id ) {
		if ( ! $category_id ) {
			return __( 'Uncategorized', 'sales-analytics' );
		}

		$term = get_term( $category_id, 'product_cat' );
		return ( $term && ! is_wp_error( $term ) ) ? $term->name : sprintf( '#%d', $category_id );
	}

	/**
	 * Product categories for the report's category filter dropdown.
	 */
	public static function get_all_product_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}
}
