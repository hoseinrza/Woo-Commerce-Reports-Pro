# Sales Analytics

Sales Analytics is a WooCommerce reporting and business intelligence plugin designed to help store owners understand product performance, sales trends, and revenue insights.

The plugin provides a scalable foundation for advanced eCommerce analytics by collecting sales data, generating reports, and presenting meaningful insights through a modern WordPress admin interface.

## Features

- Product sales reports
- Revenue and order analytics
- Sales trend visualization
- Product performance tracking
- Date-based filtering
- Category-based analysis
- Export reports (CSV/Excel)
- Optimized queries for large WooCommerce stores
- HPOS compatibility
- Daily sales data snapshots

## Architecture

Built with:

- PHP (Object-Oriented Programming)
- WordPress Plugin API
- WooCommerce APIs
- MySQL optimized queries
- AJAX-based admin interactions
- Chart.js visualization

### Plugin structure

```
sales-analytics.php            Plugin bootstrap, activation/deactivation hooks, HPOS declaration
includes/
  class-sa-activator.php       Creates the sa_daily_sales snapshot table, schedules the cron
  class-sa-deactivator.php     Clears the scheduled cron event
  class-sales-analytics.php    Wires up the admin, AJAX, and cron components
  class-sa-data.php            Report queries: summary, trend, top products, category breakdown
  class-sa-cron.php            Nightly snapshot of the previous day's sales
  class-sa-ajax.php            AJAX endpoints backing the dashboard
  class-sa-export.php          CSV export
  class-sa-admin.php           Admin menu registration and asset enqueuing
admin/
  views/dashboard.php          Dashboard markup (filters, summary cards, charts, tables)
  css/admin.css                Dashboard styling
  js/admin.js                  Dashboard filtering, AJAX calls, Chart.js rendering
assets/js/                     Vendored Chart.js build
```

### How reporting stays fast on large stores

Every night, `SA_Cron` aggregates the previous day's orders into a small `wp_sa_daily_sales` table (one row per product per day). Historical report ranges are read straight from that table instead of re-scanning the full orders history. Only the current, not-yet-snapshotted day is computed live from WooCommerce orders via `wc_get_orders()`, keeping that live query bounded to a single day's data. The plugin declares compatibility with WooCommerce's High-Performance Order Storage (HPOS) and works whether orders live in the legacy posts table or the custom order tables.

## Roadmap

Future improvements:

- Profit and margin analysis
- Customer analytics
- Product recommendations
- Sales forecasting
- AI-powered business insights
- Multi-store reporting

## License

MIT License
