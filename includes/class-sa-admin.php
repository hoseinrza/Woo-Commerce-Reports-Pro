<?php
/**
 * Admin menu registration and asset loading.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SA_Admin {

	const PAGE_SLUG = 'sales-analytics';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_backfill_notice' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Sales Analytics', 'sales-analytics' ),
			__( 'Sales Analytics', 'sales-analytics' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			56
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'sa-admin', SA_PLUGIN_URL . 'admin/css/admin.css', array(), SA_VERSION );

		wp_enqueue_script( 'sa-chartjs', SA_PLUGIN_URL . 'assets/js/chart.umd.min.js', array(), '4.4.4', true );

		wp_enqueue_script( 'sa-admin', SA_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery', 'sa-chartjs' ), SA_VERSION, true );

		wp_localize_script(
			'sa-admin',
			'SalesAnalytics',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'exportUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( SA_Ajax::NONCE_ACTION ),
				'currency'   => get_woocommerce_currency_symbol(),
				'i18n'       => array(
					'loading' => __( 'Loading…', 'sales-analytics' ),
					'noData'  => __( 'No sales found for this period.', 'sales-analytics' ),
				),
			)
		);
	}

	public function render_dashboard() {
		$categories = SA_Data::get_all_product_categories();
		include SA_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	/**
	 * Let the store owner know historical orders are still being
	 * imported, instead of leaving them wondering why past dates look
	 * empty on the dashboard while the backfill job runs in the
	 * background.
	 */
	public function render_backfill_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		if ( get_option( 'sa_backfill_complete' ) ) {
			return;
		}

		$cursor = get_option( 'sa_backfill_cursor' );
		?>
		<div class="notice notice-info">
			<p>
			<?php
			if ( $cursor ) {
				printf(
					/* translators: %s: date the backfill has reached so far. */
					esc_html__( 'Sales Analytics is still importing historical orders (caught up through %s so far). Older dates will keep filling in over the next few minutes.', 'sales-analytics' ),
					esc_html( $cursor )
				);
			} else {
				esc_html_e( 'Sales Analytics is importing your historical orders in the background. This dashboard will include your full order history shortly.', 'sales-analytics' );
			}
			?>
			</p>
		</div>
		<?php
	}
}
