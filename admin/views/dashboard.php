<?php
/**
 * Sales Analytics dashboard view.
 *
 * @var WP_Term[] $categories
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap sa-dashboard">
	<h1><?php esc_html_e( 'Sales Analytics', 'sales-analytics' ); ?></h1>

	<div class="sa-toolbar">
		<div class="sa-field">
			<label for="sa-start-date"><?php esc_html_e( 'From', 'sales-analytics' ); ?></label>
			<input type="date" id="sa-start-date" value="<?php echo esc_attr( date( 'Y-m-d', strtotime( '-29 days' ) ) ); ?>" />
		</div>
		<div class="sa-field">
			<label for="sa-end-date"><?php esc_html_e( 'To', 'sales-analytics' ); ?></label>
			<input type="date" id="sa-end-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
		</div>
		<div class="sa-field">
			<label for="sa-category"><?php esc_html_e( 'Category', 'sales-analytics' ); ?></label>
			<select id="sa-category">
				<option value="0"><?php esc_html_e( 'All categories', 'sales-analytics' ); ?></option>
				<?php foreach ( $categories as $category ) : ?>
					<option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="sa-field sa-field-actions">
			<button type="button" class="button button-primary" id="sa-apply-filters"><?php esc_html_e( 'Apply', 'sales-analytics' ); ?></button>
			<button type="button" class="button" id="sa-export-csv"><?php esc_html_e( 'Export CSV', 'sales-analytics' ); ?></button>
		</div>
	</div>

	<div class="sa-summary-cards" id="sa-summary-cards">
		<div class="sa-card">
			<span class="sa-card-label"><?php esc_html_e( 'Total Revenue', 'sales-analytics' ); ?></span>
			<span class="sa-card-value" id="sa-total-revenue">&mdash;</span>
		</div>
		<div class="sa-card">
			<span class="sa-card-label"><?php esc_html_e( 'Total Orders', 'sales-analytics' ); ?></span>
			<span class="sa-card-value" id="sa-total-orders">&mdash;</span>
		</div>
		<div class="sa-card">
			<span class="sa-card-label"><?php esc_html_e( 'Items Sold', 'sales-analytics' ); ?></span>
			<span class="sa-card-value" id="sa-items-sold">&mdash;</span>
		</div>
		<div class="sa-card">
			<span class="sa-card-label"><?php esc_html_e( 'Average Order Value', 'sales-analytics' ); ?></span>
			<span class="sa-card-value" id="sa-average-order-value">&mdash;</span>
		</div>
	</div>

	<div class="sa-panel">
		<h2><?php esc_html_e( 'Sales Trend', 'sales-analytics' ); ?></h2>
		<canvas id="sa-trend-chart" height="90"></canvas>
	</div>

	<div class="sa-columns">
		<div class="sa-panel sa-panel-half">
			<h2><?php esc_html_e( 'Top Products', 'sales-analytics' ); ?></h2>
			<table class="widefat striped" id="sa-top-products-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Product', 'sales-analytics' ); ?></th>
						<th><?php esc_html_e( 'Items Sold', 'sales-analytics' ); ?></th>
						<th><?php esc_html_e( 'Revenue', 'sales-analytics' ); ?></th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>

		<div class="sa-panel sa-panel-half">
			<h2><?php esc_html_e( 'Sales by Category', 'sales-analytics' ); ?></h2>
			<canvas id="sa-category-chart" height="200"></canvas>
		</div>
	</div>
</div>
