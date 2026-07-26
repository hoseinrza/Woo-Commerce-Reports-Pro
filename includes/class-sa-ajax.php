<?php
/**
 * AJAX endpoints backing the admin dashboard.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Ajax {

	const NONCE_ACTION = 'sa_dashboard';

	public function __construct() {
		add_action( 'wp_ajax_sa_get_report', array( $this, 'get_report' ) );
		add_action( 'wp_ajax_sa_export_csv', array( $this, 'export_csv' ) );
	}

	private function check_request() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view this report.', 'sales-analytics' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	private function get_request_range() {
		$start_date  = isset( $_REQUEST['start_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['start_date'] ) ) : date( 'Y-m-d', strtotime( '-29 days' ) );
		$end_date    = isset( $_REQUEST['end_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['end_date'] ) ) : current_time( 'Y-m-d' );
		$category_id = isset( $_REQUEST['category_id'] ) ? absint( $_REQUEST['category_id'] ) : 0;

		if ( ! self::is_valid_date( $start_date ) ) {
			$start_date = date( 'Y-m-d', strtotime( '-29 days' ) );
		}

		if ( ! self::is_valid_date( $end_date ) ) {
			$end_date = current_time( 'Y-m-d' );
		}

		if ( $start_date > $end_date ) {
			list( $start_date, $end_date ) = array( $end_date, $start_date );
		}

		return array( $start_date, $end_date, $category_id );
	}

	private static function is_valid_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	public function get_report() {
		$this->check_request();

		list( $start_date, $end_date, $category_id ) = $this->get_request_range();

		$report = SA_Data::get_report( $start_date, $end_date, $category_id );

		wp_send_json_success( $report );
	}

	public function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export this report.', 'sales-analytics' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'nonce' );

		list( $start_date, $end_date, $category_id ) = $this->get_request_range();

		$report = SA_Data::get_report( $start_date, $end_date, $category_id );

		SA_Export::stream_csv( $report, $start_date, $end_date );
	}
}
