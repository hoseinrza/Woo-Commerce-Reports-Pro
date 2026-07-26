<?php
/**
 * Scheduled tasks that keep the sa_daily_sales snapshot table in sync:
 * a nightly job for the previous day, and a self-rescheduling batch
 * job that backfills history for orders that existed before the
 * plugin started snapshotting (activation, or an upgrade that changed
 * the schema).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Cron {

	const BACKFILL_CURSOR_OPTION   = 'sa_backfill_cursor';
	const BACKFILL_COMPLETE_OPTION = 'sa_backfill_complete';

	public function __construct() {
		add_action( 'sa_daily_snapshot_event', array( $this, 'run_snapshot' ) );
		add_action( 'sa_backfill_batch_event', array( $this, 'run_backfill_batch' ) );
	}

	/**
	 * Snapshot a single day's sales. Safe to run more than once for the
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

	/**
	 * Snapshot a batch of historical days, then reschedule itself
	 * shortly after until it reaches yesterday. Runs in the background
	 * via WP-Cron so it never blocks a page load, no matter how many
	 * years of order history a store has.
	 */
	public function run_backfill_batch() {
		if ( get_option( self::BACKFILL_COMPLETE_OPTION ) ) {
			return;
		}

		$yesterday = date( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) );

		$cursor = get_option( self::BACKFILL_CURSOR_OPTION );
		if ( ! $cursor ) {
			$cursor = SA_Data::get_earliest_order_date();
			if ( ! $cursor ) {
				update_option( self::BACKFILL_COMPLETE_OPTION, 1 );
				return;
			}
		}

		if ( $cursor > $yesterday ) {
			update_option( self::BACKFILL_COMPLETE_OPTION, 1 );
			delete_option( self::BACKFILL_CURSOR_OPTION );
			return;
		}

		$batch_size = apply_filters( 'sa_backfill_batch_size', 15 );
		$date       = $cursor;

		for ( $i = 0; $i < $batch_size && $date <= $yesterday; $i++ ) {
			$this->run_snapshot( $date );
			$date = date( 'Y-m-d', strtotime( $date . ' +1 day' ) );
		}

		if ( $date > $yesterday ) {
			update_option( self::BACKFILL_COMPLETE_OPTION, 1 );
			delete_option( self::BACKFILL_CURSOR_OPTION );
		} else {
			update_option( self::BACKFILL_CURSOR_OPTION, $date );
			wp_schedule_single_event( time() + 5, 'sa_backfill_batch_event' );
		}
	}

	private static function parse_category_ids( $category_ids ) {
		if ( empty( $category_ids ) ) {
			return array();
		}

		return array_filter( array_map( 'intval', explode( ',', $category_ids ) ) );
	}
}
