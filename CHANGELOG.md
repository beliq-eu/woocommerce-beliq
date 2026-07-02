# Changelog

## 0.1.0 (unreleased)

- Framework-agnostic core: invoice value objects, InvoiceMapper (EN 16931 category derivation, tax grouping, rounding, totals), BeliqClient over a cURL HTTP seam.
- WooCommerce order adapter: maps an order to the normalized invoice shape through a read-only seam, unit-tested without a WordPress runtime.
- Typed plugin settings with defaults and coercion.
- WooCommerce runtime: plugin bootstrap with the WordPress header and HPOS (`custom_order_tables`) compatibility declaration; a `WC_Integration` settings screen; `WcOrderData` / `WcLineData` wrappers over `WC_Order`; the `WooPluginConfigProvider` with `yes` / `no` checkbox coercion and the two order meta-key mappings (buyer VAT, buyer reference).
- Automatic generation on the configured order status, with the failure isolated from the transition; manual generate/regenerate from the order metabox and the WooCommerce order-action dropdown, with regeneration idempotency.
- Protected document storage in the uploads folder with a capability-checked download; i18n text domain and a WordPress.org `readme.txt`; WordPress security and i18n sniffs (PHPCS) in CI.
