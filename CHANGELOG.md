# Changelog

## 0.1.0 (unreleased)

- Framework-agnostic core: invoice value objects, InvoiceMapper (EN 16931 category derivation, tax grouping, rounding, totals), BeliqClient over a cURL HTTP seam.
- WooCommerce order adapter: maps an order to the normalized invoice shape through a read-only seam, unit-tested without a WordPress runtime.
- Typed plugin settings with defaults and coercion.
