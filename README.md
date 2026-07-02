# woocommerce-beliq

A WooCommerce plugin that turns store orders into compliant EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the beliq API. It generates and validates the documents; it does not send, file, or submit them anywhere.

## Status

Pass 2: WooCommerce runtime. The plugin boots as a WordPress plugin, declares HPOS compatibility, exposes a settings screen under WooCommerce > Settings > Integrations, generates a document when an order reaches the configured status, and stores it for a capability-checked download from the order screen. The framework-agnostic core and the order-to-EN 16931 mapping landed in Pass 1.

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
```

The offline suite needs no WordPress: the runtime classes reference WooCommerce symbols only inside method bodies, so the tests that exercise the pure logic (`PluginConfig`, `WooPluginConfigProvider`, the adapter, the mapper, the client) never load them. A live smoke against the beliq API is gated on `BELIQ_API_KEY`.
