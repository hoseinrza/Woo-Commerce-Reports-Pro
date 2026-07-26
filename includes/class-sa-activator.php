<?php
/**
 * Fired on plugin activation, and re-run on upgrade to keep the schema
 * in sync (dbDelta is safe to run repeatedly).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Activator {

	public static function activate() {
		self::create_tables();
		self::schedule_events();
		self::maybe_schedule_backfill();
	}

	/**
	 * Run on every request (cheap - bails immediately) so that stores
	 * that upgrade the plugin without deactivating/reactivating still
	 * get new tables, indexes, and a historical backfill.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'sa_db_version' ) === SA_VERSION ) {
			return;
		}

		self::create_tables();
		self::schedule_events();
		self::maybe_schedule_backfill();
	}

	/**
	 * Kick off the background job that snapshots every day from the
	 * store's earliest order up to yesterday, so historical orders
	 * placed before the plugin started collecting data (or before a
	 * schema upgrade) still show up in reports - the snapshot table
	 * otherwise only ever fills in going forward from the nightly cron.
	 *
	 * Uses the raw option name (matching SA_Cron::BACKFILL_COMPLETE_OPTION)
	 * rather than the class constant: this runs from the activation hook,
	 * before class-sa-cron.php has necessarily been require'd.
	 */
	private static function maybe_schedule_backfill() {
		if ( get_option( 'sa_backfill_complete' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'sa_backfill_batch_event' ) ) {
			wp_schedule_single_event( time() + 10, 'sa_backfill_batch_event' );
		}
	}

	/**
	 * Create the custom tables used for daily sales snapshots.
	 *
	 * sa_daily_sales holds one row per product per day so reporting
	 * doesn't need to re-aggregate the full orders history.
	 *
	 * sa_daily_sales_categories normalizes each product's category
	 * membership into its own indexed row, so category-filtered
	 * reports can use an indexed lookup instead of a FIND_IN_SET()
	 * scan over a CSV column - the difference between an index seek
	 * and a full table scan on a store with years of order history.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sales_table = $wpdb->prefix . SA_SNAPSHOT_TABLE;
		$sales_sql   = "CREATE TABLE {$sales_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_date DATE NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			category_ids VARCHAR(255) DEFAULT '',
			orders_count INT UNSIGNED NOT NULL DEFAULT 0,
			items_sold INT UNSIGNED NOT NULL DEFAULT 0,
			net_revenue DECIMAL(18,4) NOT NULL DEFAULT 0,
			gross_revenue DECIMAL(18,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY sa_date_product (snapshot_date, product_id),
			KEY snapshot_date (snapshot_date),
			KEY product_id (product_id)
		) {$charset_collate};";

		$categories_table = $wpdb->prefix . SA_SNAPSHOT_CATEGORIES_TABLE;
		$categories_sql   = "CREATE TABLE {$categories_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_date DATE NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			category_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sa_date_product_category (snapshot_date, product_id, category_id),
			KEY sa_category_date (category_id, snapshot_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sales_sql );
		dbDelta( $categories_sql );

		update_option( 'sa_db_version', SA_VERSION );
	}

	private static function schedule_events() {
		if ( ! wp_next_scheduled( 'sa_daily_snapshot_event' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 00:30:00' ), 'daily', 'sa_daily_snapshot_event' );
		}
	}
}
