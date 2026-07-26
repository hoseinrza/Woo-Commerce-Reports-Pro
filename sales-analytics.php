<?php
/**
 * Plugin Name: Sales Analytics
 * Plugin URI: https://github.com/hoseinrza/woo-commerce-reports-pro
 * Description: A WooCommerce reporting and business intelligence plugin that helps store owners understand product performance, sales trends, and revenue insights.
 * Version: 1.0.0
 * Author: Sales Analytics
 * Text Domain: sales-analytics
 * Domain Path: /languages
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SA_VERSION', '1.0.0' );
define( 'SA_PLUGIN_FILE', __FILE__ );
define( 'SA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SA_SNAPSHOT_TABLE', 'sa_daily_sales' );
define( 'SA_SNAPSHOT_CATEGORIES_TABLE', 'sa_daily_sales_categories' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Bail out (with an admin notice) if WooCommerce is not active.
 */
function sa_is_woocommerce_active() {
	return in_array(
		'woocommerce/woocommerce.php',
		apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ),
		true
	) || ( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins', array() ) ) );
}

function sa_woocommerce_missing_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Sales Analytics requires WooCommerce to be installed and active.', 'sales-analytics' ); ?></p>
	</div>
	<?php
}

require_once SA_PLUGIN_DIR . 'includes/class-sa-activator.php';
require_once SA_PLUGIN_DIR . 'includes/class-sa-deactivator.php';

register_activation_hook( __FILE__, array( 'SA_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SA_Deactivator', 'deactivate' ) );

/**
 * Kick off the plugin once all plugins are loaded so we can reliably
 * detect whether WooCommerce is available.
 */
add_action( 'plugins_loaded', 'sa_init_plugin' );

function sa_init_plugin() {
	if ( ! sa_is_woocommerce_active() ) {
		add_action( 'admin_notices', 'sa_woocommerce_missing_notice' );
		return;
	}

	load_plugin_textdomain( 'sales-analytics', false, dirname( SA_PLUGIN_BASENAME ) . '/languages' );

	SA_Activator::maybe_upgrade();

	require_once SA_PLUGIN_DIR . 'includes/class-sa-data.php';
	require_once SA_PLUGIN_DIR . 'includes/class-sa-cron.php';
	require_once SA_PLUGIN_DIR . 'includes/class-sa-export.php';
	require_once SA_PLUGIN_DIR . 'includes/class-sa-ajax.php';
	require_once SA_PLUGIN_DIR . 'includes/class-sa-admin.php';
	require_once SA_PLUGIN_DIR . 'includes/class-sales-analytics.php';

	Sales_Analytics::instance();
}
