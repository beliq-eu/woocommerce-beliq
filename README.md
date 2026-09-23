# woocommerce-beliq

A WooCommerce plugin that turns store orders into EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the beliq API. It generates and validates the documents; it does not send, file, or submit them anywhere.

## Status

Version 0.1.0, not published: there is no git tag, no WordPress.org listing and no Packagist listing. Passes 1 to 5 in `ROADMAP.md` are done:

- The plugin boots as a WordPress plugin, declares HPOS compatibility, exposes a settings screen under WooCommerce > Settings > Integration, generates a document when an order reaches the configured status, and stores it for a capability-checked download from the order screen.
- The live smoke in `smoke/` drives a real WordPress + WooCommerce store through the whole order-to-document path. On 2026-09-07 it passed 38 of 38 checks against the production beliq API on WordPress 7.1 and WooCommerce 11.1.0.
- The official wp.org Plugin Check runs on the distribution in CI (`plugin-check/run.sh --ignore-calendar`, which fails on any error) and weekly with the calendar-driven check included (`.github/workflows/wporg-currency.yml`).

Next are the WordPress.org submission, which needs the operator's wp.org account (`PASS-3-SMOKE-ROADMAP.md` section 3.3), and a Packagist listing as `beliq/woocommerce-beliq`. Both are under "Operator-gated" in `ROADMAP.md`.

## How it works

- Settings live in a `WC_Integration` (`src/Integration/InvoiceIntegration.php`); `WooPluginConfigProvider` reads them into the typed `PluginConfig`, coercing WooCommerce's `yes` / `no` checkbox values.
- `OrderStatusTrigger` fires on `woocommerce_order_status_changed`; when the order reaches the configured status it runs `InvoiceGenerator`, and it never lets a failure break the transition (errors go to the WooCommerce log, source `beliq`).
- `WcOrderData` / `WcLineData` wrap a `WC_Order` behind the `OrderData` / `LineData` seam (line net from `get_total()`, rate from `WC_Tax`, buyer VAT and reference from the configured meta keys), so `WooOrderAdapter` maps against plain data.
- `InvoiceGenerator` maps the order, applies the business-only gate, calls beliq, and hands the bytes to `DocumentStore`, which writes them to a protected uploads subdirectory and records the location in order meta.
- `OrderMetabox` shows the status and a download button; `OrderActions` serves the capability-checked download and a manual (re)generate, and adds the native WooCommerce order-action entry.

## Development

```
composer test          # PHPUnit (offline: mapper, client, config, provider, adapter)
composer phpcs          # WordPress security + i18n sniffs over the runtime code
composer scrub:check    # no em-dash
composer archive:check  # git archive HEAD (what Packagist ships) = the wp.org distribution + composer.json, README.md, CHANGELOG.md
```

The offline suite needs no WordPress: the runtime classes reference WooCommerce symbols only inside method bodies, so the tests that exercise the pure logic (`PluginConfig`, `WooPluginConfigProvider`, the adapter, the mapper, the client) never load them. A live smoke against the beliq API is gated on `BELIQ_API_KEY`.
