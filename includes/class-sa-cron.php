<?php
/**
 * Scheduled task that snapshots the previous day's sales into the
 * sa_daily_sales table so historical reporting doesn't need to
 * re-aggregate raw orders on every page load.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Cron {

	public function __construct() {
		add_action( 'sa_daily_snapshot_event', array( $this, 'run_snapshot' ) );
	}

	/**
	 * Snapshot yesterday's sales. Safe to run more than once for the
	 * same day since rows are upserted by (snapshot_date, product_id).
	 */
	public function run_snapshot( $date = null ) {
		if ( ! $date ) {
			$date = date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) );
		}

		$rows = SA_Data::compute_live_day_rows( $date );

		global $wpdb;
		$table            = $wpdb->prefix . SA_SNAPSHOT_TABLE;
		$categories_table = $wpdb->prefix . SA_SNAPSHOT_CATEGORIES_TABLE;

		// Re-synced on every run for this date, so re-snapshotting never
		// leaves stale category rows behind (e.g. a product's category
		// changed after the first snapshot for that day).
		$wpdb->delete( $categories_table, array( 'snapshot_date' => $date ), array( '%s' ) );

		foreach ( $rows as $row ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (snapshot_date, product_id, category_ids, orders_count, items_sold, net_revenue, gross_revenue)
					 VALUES (%s, %d, %s, %d, %d, %f, %f)
					 ON DUPLICATE KEY UPDATE
						category_ids = VALUES(category_ids),
						orders_count = VALUES(orders_count),
						items_sold = VALUES(items_sold),
						net_revenue = VALUES(net_revenue),
						gross_revenue = VALUES(gross_revenue)",
					$date,
					$row['product_id'],
					$row['category_ids'],
					$row['orders_count'],
					$row['items_sold'],
					$row['net_revenue'],
					$row['gross_revenue']
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			foreach ( self::parse_category_ids( $row['category_ids'] ) as $category_id ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$categories_table} (snapshot_date, product_id, category_id) VALUES (%s, %d, %d)",
						$date,
						$row['product_id'],
						$category_id
					)
				); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}

	private static function parse_category_ids( $category_ids ) {
		if ( empty( $category_ids ) ) {
			return array();
		}

		return array_filter( array_map( 'intval', explode( ',', $category_ids ) ) );
	}
}
