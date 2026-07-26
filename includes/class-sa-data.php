<?php
/**
 * Data access layer for Sales Analytics reports.
 *
 * Historical days are read from the pre-aggregated snapshot table
 * (built nightly by SA_Cron) so reporting stays fast on stores with a
 * large order history. The current, not-yet-snapshotted day is
 * computed live from WooCommerce orders and merged in. All snapshot
 * aggregation (sums, grouping, ordering, limiting) is pushed down to
 * SQL rather than pulled row-by-row into PHP.
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
		list( $snapshot_start, $snapshot_end, $live_date ) = self::split_range( $start_date, $end_date );

		$live_rows = $live_date ? self::compute_live_day_rows( $live_date, $category_id ) : array();

		$daily      = self::get_daily_totals( $snapshot_start, $snapshot_end, $category_id, $live_rows, $live_date );
		$top_products = self::get_top_products( $snapshot_start, $snapshot_end, $category_id, $live_rows, $live_date );
		$categories   = self::get_categories( $snapshot_start, $snapshot_end, $category_id, $live_rows, $live_date );

		return array(
			'summary'      => self::summary_from_daily( $daily ),
			'trend'        => self::trend_from_daily( $daily, $start_date, $end_date ),
			'top_products' => $top_products,
			'categories'   => $categories,
		);
	}

	/**
	 * Split a requested range into the part servable from the snapshot
	 * table and, if the range includes today, the single day that must
	 * be computed live.
	 *
	 * @return array{0: ?string, 1: ?string, 2: ?string}
	 */
	private static function split_range( $start_date, $end_date ) {
		$today = current_time( 'Y-m-d' );

		$snapshot_end = ( $end_date >= $today ) ? date( 'Y-m-d', strtotime( $today . ' -1 day' ) ) : $end_date;

		$snapshot_start = ( $start_date <= $snapshot_end ) ? $start_date : null;
		$snapshot_end   = $snapshot_start ? $snapshot_end : null;

		$live_date = ( $end_date >= $today && $start_date <= $today ) ? $today : null;

		return array( $snapshot_start, $snapshot_end, $live_date );
	}

	/**
	 * One row per day: SUM(revenue), SUM(items), and the day's distinct
	 * order count. Aggregated in SQL so only #days rows are ever
	 * transferred, instead of #days x #products.
	 */
	private static function get_daily_totals( $snapshot_start, $snapshot_end, $category_id, array $live_rows, $live_date ) {
		$by_day = array();

		if ( $snapshot_start ) {
			global $wpdb;
			$table = $wpdb->prefix . SA_SNAPSHOT_TABLE;

			$sql  = "SELECT snapshot_date,
						SUM(net_revenue) AS net_revenue,
						SUM(gross_revenue) AS gross_revenue,
						SUM(items_sold) AS items_sold,
						MAX(orders_count) AS orders_count
					 FROM {$table}
					 WHERE snapshot_date BETWEEN %s AND %s";
			$args = array( $snapshot_start, $snapshot_end );

			if ( $category_id ) {
				$sql   .= ' AND FIND_IN_SET(%d, category_ids)';
				$args[] = $category_id;
			}

			$sql .= ' GROUP BY snapshot_date';

			$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			foreach ( $results as $row ) {
				$by_day[ $row['snapshot_date'] ] = array(
					'date'          => $row['snapshot_date'],
					'net_revenue'   => (float) $row['net_revenue'],
					'gross_revenue' => (float) $row['gross_revenue'],
					'items_sold'    => (int) $row['items_sold'],
					'orders_count'  => (int) $row['orders_count'],
				);
			}
		}

		if ( $live_date && $live_rows ) {
			$net_revenue   = 0.0;
			$gross_revenue = 0.0;
			$items_sold    = 0;
			$orders_count  = 0;

			foreach ( $live_rows as $row ) {
				$net_revenue   += (float) $row['net_revenue'];
				$gross_revenue += (float) $row['gross_revenue'];
				$items_sold    += (int) $row['items_sold'];
				$orders_count   = max( $orders_count, (int) $row['orders_count'] );
			}

			$by_day[ $live_date ] = array(
				'date'          => $live_date,
				'net_revenue'   => $net_revenue,
				'gross_revenue' => $gross_revenue,
				'items_sold'    => $items_sold,
				'orders_count'  => $orders_count,
			);
		}

		return $by_day;
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

		$rows          = array(); // product_id => row
		$category_cache = array(); // product_id => term ids, avoids repeat lookups across orders

		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$product_id = $item->get_product_id();
				if ( ! $product_id ) {
					continue;
				}

				if ( ! isset( $category_cache[ $product_id ] ) ) {
					$category_cache[ $product_id ] = wc_get_product_term_ids( $product_id, 'product_cat' );
				}
				$cat_ids = $category_cache[ $product_id ];

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

	private static function summary_from_daily( array $by_day ) {
		$net_revenue   = 0.0;
		$gross_revenue = 0.0;
		$items_sold    = 0;
		$orders_count  = 0;

		foreach ( $by_day as $day ) {
			$net_revenue   += $day['net_revenue'];
			$gross_revenue += $day['gross_revenue'];
			$items_sold    += $day['items_sold'];
			$orders_count  += $day['orders_count'];
		}

		return array(
			'total_revenue'       => round( $net_revenue, 2 ),
			'total_gross_revenue' => round( $gross_revenue, 2 ),
			'total_orders'        => $orders_count,
			'items_sold'          => $items_sold,
			'average_order_value' => $orders_count ? round( $net_revenue / $orders_count, 2 ) : 0,
		);
	}

	private static function trend_from_daily( array $by_day, $start_date, $end_date ) {
		$period = new DatePeriod(
			new DateTime( $start_date ),
			new DateInterval( 'P1D' ),
			( new DateTime( $end_date ) )->modify( '+1 day' )
		);

		$trend = array();

		foreach ( $period as $day ) {
			$date = $day->format( 'Y-m-d' );
			$row  = isset( $by_day[ $date ] ) ? $by_day[ $date ] : array( 'net_revenue' => 0.0, 'orders_count' => 0 );

			$trend[] = array(
				'date'    => $date,
				'revenue' => round( $row['net_revenue'], 2 ),
				'orders'  => $row['orders_count'],
			);
		}

		return $trend;
	}

	/**
	 * Top products by revenue. Grouping, ordering, and limiting happen
	 * in SQL for the snapshotted portion of the range so only the
	 * requested number of rows is ever pulled for historical data.
	 */
	private static function get_top_products( $snapshot_start, $snapshot_end, $category_id, array $live_rows, $live_date, $limit = 20 ) {
		$products = array(); // product_id => array

		if ( $snapshot_start ) {
			global $wpdb;
			$table = $wpdb->prefix . SA_SNAPSHOT_TABLE;

			$sql  = "SELECT product_id, SUM(items_sold) AS items_sold, SUM(net_revenue) AS net_revenue
					 FROM {$table}
					 WHERE snapshot_date BETWEEN %s AND %s";
			$args = array( $snapshot_start, $snapshot_end );

			if ( $category_id ) {
				$sql   .= ' AND FIND_IN_SET(%d, category_ids)';
				$args[] = $category_id;
			}

			$sql   .= ' GROUP BY product_id ORDER BY net_revenue DESC LIMIT %d';
			$args[] = $limit;

			$results = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			foreach ( $results as $row ) {
				$product_id = (int) $row['product_id'];
				$products[ $product_id ] = array(
					'product_id'  => $product_id,
					'items_sold'  => (int) $row['items_sold'],
					'net_revenue' => (float) $row['net_revenue'],
				);
			}
		}

		if ( $live_date && $live_rows ) {
			foreach ( $live_rows as $row ) {
				$product_id = (int) $row['product_id'];
				if ( ! isset( $products[ $product_id ] ) ) {
					$products[ $product_id ] = array(
						'product_id'  => $product_id,
						'items_sold'  => 0,
						'net_revenue' => 0.0,
					);
				}
				$products[ $product_id ]['items_sold']  += (int) $row['items_sold'];
				$products[ $product_id ]['net_revenue'] += (float) $row['net_revenue'];
			}
		}

		usort(
			$products,
			function ( $a, $b ) {
				return $b['net_revenue'] <=> $a['net_revenue'];
			}
		);

		$products = array_slice( array_values( $products ), 0, $limit );

		$names = self::get_product_names( wp_list_pluck( $products, 'product_id' ) );

		foreach ( $products as &$product ) {
			$product['name']        = isset( $names[ $product['product_id'] ] ) ? $names[ $product['product_id'] ] : sprintf( '#%d', $product['product_id'] );
			$product['net_revenue'] = round( $product['net_revenue'], 2 );
		}

		return $products;
	}

	/**
	 * Sales grouped by category. Category assignments are stored as a
	 * CSV column on the snapshot table (a product can belong to more
	 * than one category), so this still needs product-level rows to
	 * explode per category — but only the columns that are needed.
	 */
	private static function get_categories( $snapshot_start, $snapshot_end, $category_id, array $live_rows, $live_date ) {
		$categories = array();

		$product_rows = array();

		if ( $snapshot_start ) {
			global $wpdb;
			$table = $wpdb->prefix . SA_SNAPSHOT_TABLE;

			$sql  = "SELECT product_id, category_ids, SUM(items_sold) AS items_sold, SUM(net_revenue) AS net_revenue
					 FROM {$table}
					 WHERE snapshot_date BETWEEN %s AND %s";
			$args = array( $snapshot_start, $snapshot_end );

			if ( $category_id ) {
				$sql   .= ' AND FIND_IN_SET(%d, category_ids)';
				$args[] = $category_id;
			}

			$sql .= ' GROUP BY product_id, category_ids';

			$product_rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( $live_date && $live_rows ) {
			$product_rows = array_merge( $product_rows, $live_rows );
		}

		foreach ( $product_rows as $row ) {
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
						'category_id' => $cat_id,
						'items_sold'  => 0,
						'net_revenue' => 0.0,
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

		$names = self::get_category_names( wp_list_pluck( $categories, 'category_id' ) );

		foreach ( $categories as &$category ) {
			$category['name']        = isset( $names[ $category['category_id'] ] ) ? $names[ $category['category_id'] ] : sprintf( '#%d', $category['category_id'] );
			$category['net_revenue'] = round( $category['net_revenue'], 2 );
		}

		return array_values( $categories );
	}

	/**
	 * Batch-resolve product names in a single query instead of one
	 * wc_get_product() call per row.
	 */
	private static function get_product_names( array $product_ids ) {
		$product_ids = array_filter( array_map( 'intval', $product_ids ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$names = array();
		foreach ( wc_get_products( array( 'include' => $product_ids, 'limit' => -1 ) ) as $product ) {
			$names[ $product->get_id() ] = $product->get_name();
		}

		return $names;
	}

	/**
	 * Batch-resolve category names in a single query instead of one
	 * get_term() call per row.
	 */
	private static function get_category_names( array $category_ids ) {
		$category_ids = array_filter( array_map( 'intval', $category_ids ) );

		$names = array( 0 => __( 'Uncategorized', 'sales-analytics' ) );
		if ( empty( $category_ids ) ) {
			return $names;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'include'    => $category_ids,
				'hide_empty' => false,
			)
		);

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$names[ $term->term_id ] = $term->name;
			}
		}

		return $names;
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
