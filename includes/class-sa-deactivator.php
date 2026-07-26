<?php
/**
 * Fired on plugin deactivation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Deactivator {

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'sa_daily_snapshot_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'sa_daily_snapshot_event' );
		}

		$backfill_timestamp = wp_next_scheduled( 'sa_backfill_batch_event' );
		if ( $backfill_timestamp ) {
			wp_unschedule_event( $backfill_timestamp, 'sa_backfill_batch_event' );
		}
	}
}
