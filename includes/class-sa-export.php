<?php
/**
 * CSV export for sales reports.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Export {

	/**
	 * Stream the top-products breakdown of a report as a CSV download
	 * and terminate the request.
	 */
	public static function stream_csv( array $report, $start_date, $end_date ) {
		$filename = sprintf( 'sales-analytics-%s-to-%s.csv', $start_date, $end_date );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		fputcsv( $output, array( __( 'Sales Analytics Report', 'sales-analytics' ) ) );
		fputcsv( $output, array( __( 'Date range', 'sales-analytics' ), $start_date . ' - ' . $end_date ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( __( 'Summary', 'sales-analytics' ) ) );
		fputcsv( $output, array( __( 'Total revenue', 'sales-analytics' ), $report['summary']['total_revenue'] ) );
		fputcsv( $output, array( __( 'Total orders', 'sales-analytics' ), $report['summary']['total_orders'] ) );
		fputcsv( $output, array( __( 'Items sold', 'sales-analytics' ), $report['summary']['items_sold'] ) );
		fputcsv( $output, array( __( 'Average order value', 'sales-analytics' ), $report['summary']['average_order_value'] ) );
		fputcsv( $output, array() );

		fputcsv( $output, array( __( 'Daily trend', 'sales-analytics' ) ) );
		fputcsv( $output, array( __( 'Date', 'sales-analytics' ), __( 'Revenue', 'sales-analytics' ), __( 'Orders', 'sales-analytics' ) ) );
		foreach ( $report['trend'] as $day ) {
			fputcsv( $output, array( $day['date'], $day['revenue'], $day['orders'] ) );
		}
		fputcsv( $output, array() );

		fputcsv( $output, array( __( 'Top products', 'sales-analytics' ) ) );
		fputcsv( $output, array( __( 'Product', 'sales-analytics' ), __( 'Items sold', 'sales-analytics' ), __( 'Revenue', 'sales-analytics' ) ) );
		foreach ( $report['top_products'] as $product ) {
			fputcsv( $output, array( $product['name'], $product['items_sold'], $product['net_revenue'] ) );
		}
		fputcsv( $output, array() );

		fputcsv( $output, array( __( 'Sales by category', 'sales-analytics' ) ) );
		fputcsv( $output, array( __( 'Category', 'sales-analytics' ), __( 'Items sold', 'sales-analytics' ), __( 'Revenue', 'sales-analytics' ) ) );
		foreach ( $report['categories'] as $category ) {
			fputcsv( $output, array( $category['name'], $category['items_sold'], $category['net_revenue'] ) );
		}

		fclose( $output );
		exit;
	}
}
