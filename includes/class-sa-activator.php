<?php
/**
 * Fired on plugin activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Activator {

	public static function activate() {
		self::create_tables();
		self::schedule_events();
	}

	/**
	 * Create the custom table used to store daily sales snapshots.
	 * Snapshotting keeps reporting fast on large stores by avoiding
	 * repeated aggregation over the full orders table.
	 */
	private static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . SA_SNAPSHOT_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'sa_db_version', SA_VERSION );
	}

	private static function schedule_events() {
		if ( ! wp_next_scheduled( 'sa_daily_snapshot_event' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 00:30:00' ), 'daily', 'sa_daily_snapshot_event' );
		}
	}
}
