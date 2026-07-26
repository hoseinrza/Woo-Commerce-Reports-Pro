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

- **Nightly pre-aggregation.** `SA_Cron` aggregates each day's orders into a small `wp_sa_daily_sales` table (one row per product per day). Historical report ranges are read straight from that table instead of re-scanning the full orders history.
- **SQL-side aggregation.** Summary totals, the trend chart, top products, and category breakdowns are all computed with `SUM`/`GROUP BY`/`ORDER BY`/`LIMIT` in the query itself, so only the rows actually needed (days in range, top-N products) ever leave the database — not every product/day row in the range.
- **Indexed category filtering.** Category membership is normalized into `wp_sa_daily_sales_categories` (one row per product/category/day, indexed on `(category_id, snapshot_date)`), so filtering a report by category is an index lookup rather than a `FIND_IN_SET()` scan over every snapshot row.
- **Reuses WooCommerce's own analytics tables.** Computing "today" (the day not yet snapshotted) reads WooCommerce's own indexed `wc_order_product_lookup` / `wc_order_stats` tables — already maintained incrementally by WooCommerce core as orders change status — with a single indexed query, instead of loading every `WC_Order` object for the day and summing line items in PHP. On installs without those tables, it falls back to iterating orders directly, still bounded to a single day.
- **Batched lookups.** Product and category names for the dashboard tables are resolved with one batched query each, not one query per row.
- **Response caching.** A completed report is cached (via the WordPress object cache) for 5 minutes per date range/category combination, so concurrent dashboard views by different staff don't each trigger a full recompute.

### Historical backfill

The snapshot table only fills in going forward from the nightly cron, so on activation (or right after a schema-changing upgrade) it starts empty. A background job (`SA_Cron::run_backfill_batch`, triggered via `wp_schedule_single_event`) snapshots a batch of historical days at a time, starting from the store's earliest order, and reschedules itself every few seconds until it catches up to yesterday — without blocking any page load, regardless of how many years of order history the store has. While it's running, a notice on the Sales Analytics admin page shows how far the backfill has progressed.

The plugin declares compatibility with WooCommerce's High-Performance Order Storage (HPOS) and works whether orders live in the legacy posts table or the custom order tables.

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
