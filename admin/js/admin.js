(function ( $ ) {
	'use strict';

	var trendChart = null;
	var categoryChart = null;

	function formatCurrency( amount ) {
		var symbol = SalesAnalytics.currency || '';
		var value = parseFloat( amount || 0 ).toFixed( 2 );
		return symbol + value;
	}

	function getFilters() {
		return {
			start_date: $( '#sa-start-date' ).val(),
			end_date: $( '#sa-end-date' ).val(),
			category_id: $( '#sa-category' ).val(),
		};
	}

	function renderSummary( summary ) {
		$( '#sa-total-revenue' ).text( formatCurrency( summary.total_revenue ) );
		$( '#sa-total-orders' ).text( summary.total_orders );
		$( '#sa-items-sold' ).text( summary.items_sold );
		$( '#sa-average-order-value' ).text( formatCurrency( summary.average_order_value ) );
	}

	function renderTrend( trend ) {
		var labels = trend.map( function ( row ) {
			return row.date;
		} );
		var data = trend.map( function ( row ) {
			return row.revenue;
		} );

		var ctx = document.getElementById( 'sa-trend-chart' ).getContext( '2d' );

		if ( trendChart ) {
			trendChart.destroy();
		}

		trendChart = new Chart( ctx, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [ {
					label: SalesAnalytics.i18n.revenueLabel || 'Revenue',
					data: data,
					borderColor: '#7f54b3',
					backgroundColor: 'rgba(127, 84, 179, 0.15)',
					fill: true,
					tension: 0.3,
				} ],
			},
			options: {
				responsive: true,
				plugins: {
					legend: { display: false },
				},
				scales: {
					y: { beginAtZero: true },
				},
			},
		} );
	}

	function renderTopProducts( products ) {
		var $tbody = $( '#sa-top-products-table tbody' );
		$tbody.empty();

		if ( ! products.length ) {
			$tbody.append( '<tr><td colspan="3" class="sa-empty">' + SalesAnalytics.i18n.noData + '</td></tr>' );
			return;
		}

		products.forEach( function ( product ) {
			var $row = $( '<tr></tr>' );
			$row.append( $( '<td></td>' ).text( product.name ) );
			$row.append( $( '<td></td>' ).text( product.items_sold ) );
			$row.append( $( '<td></td>' ).text( formatCurrency( product.net_revenue ) ) );
			$tbody.append( $row );
		} );
	}

	function renderCategories( categories ) {
		var labels = categories.map( function ( row ) {
			return row.name;
		} );
		var data = categories.map( function ( row ) {
			return row.net_revenue;
		} );

		var ctx = document.getElementById( 'sa-category-chart' ).getContext( '2d' );

		if ( categoryChart ) {
			categoryChart.destroy();
		}

		categoryChart = new Chart( ctx, {
			type: 'doughnut',
			data: {
				labels: labels,
				datasets: [ {
					data: data,
					backgroundColor: [
						'#7f54b3', '#00a0d2', '#46b450', '#ffb900', '#dc3232',
						'#826eb4', '#00b9eb', '#7ad03a', '#f56e28', '#a4286a',
					],
				} ],
			},
			options: {
				responsive: true,
			},
		} );
	}

	function loadReport() {
		$.ajax( {
			url: SalesAnalytics.ajaxUrl,
			method: 'GET',
			data: $.extend( { action: 'sa_get_report', nonce: SalesAnalytics.nonce }, getFilters() ),
		} ).done( function ( response ) {
			if ( ! response.success ) {
				return;
			}

			renderSummary( response.data.summary );
			renderTrend( response.data.trend );
			renderTopProducts( response.data.top_products );
			renderCategories( response.data.categories );
		} );
	}

	function exportCsv() {
		var params = $.extend( { action: 'sa_export_csv', nonce: SalesAnalytics.nonce }, getFilters() );
		var query = $.param( params );
		window.location = SalesAnalytics.exportUrl + '?' + query;
	}

	$( function () {
		loadReport();

		$( '#sa-apply-filters' ).on( 'click', loadReport );
		$( '#sa-export-csv' ).on( 'click', exportCsv );
	} );
})( jQuery );
