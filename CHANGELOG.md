# Changelog

## 0.1.0 (2026-09-08)

- Totals sum the line nets as emitted. Each line net is rounded once and every sum is taken over the rounded values, so the invoice's line total (BT-106) and each VAT group's taxable amount equal the sum of the lines (BR-CO-10, BR-S-08). WooCommerce stores line nets past two decimals, and rounding their unrounded sum instead could land a cent away, which the validator rejects.
- `/v1/generate` is asked for the document's own media type. The endpoint returns the raw file by default but switches to a JSON envelope carrying the file base64-encoded as soon as the caller ranks `application/json` above the document type, so sharing the `Accept` header the JSON endpoints need would store that envelope in place of every invoice.
- Verified end to end against production `api.beliq.eu` on WooCommerce 11.1: 38 of 38 smoke checks, covering German XRechnung, French Peppol BIS, and German ZUGFeRD (hybrid PDF).
- The text domain is `beliq-e-invoicing`, matching the wp.org slug. A domain that does not match the slug is never imported into translate.wordpress.org, so no language pack could ever exist for it.
- beliq calls go through the WordPress HTTP API (`wp_remote_request`) rather than cURL directly, so a site's proxy configuration, `WP_HTTP_BLOCK_EXTERNAL` and the `http_request_args` filters apply to them.
- `Tested up to` is 7.1.
- The Output setting resolves to XML on XRechnung and Peppol BIS. Neither has a hybrid PDF, so the API answered `output=pdf` for them with a 400 on every order. The setting's own label ("PDF (hybrid, where the format supports it)") already said this is what it means.
- Framework-agnostic core: invoice value objects, InvoiceMapper (EN 16931 category derivation, tax grouping, rounding, totals), BeliqClient over a cURL HTTP seam.
- WooCommerce order adapter: maps an order to the normalized invoice shape through a read-only seam, unit-tested without a WordPress runtime.
- Typed plugin settings with defaults and coercion.
- WooCommerce runtime: plugin bootstrap with the WordPress header and HPOS (`custom_order_tables`) compatibility declaration; a `WC_Integration` settings screen; `WcOrderData` / `WcLineData` wrappers over `WC_Order`; the `WooPluginConfigProvider` with `yes` / `no` checkbox coercion and the two order meta-key mappings (buyer VAT, buyer reference).
- Automatic generation on the configured order status, with the failure isolated from the transition; manual generate/regenerate from the order metabox and the WooCommerce order-action dropdown, with regeneration idempotency.
- Protected document storage in the uploads folder with a capability-checked download; i18n text domain and a WordPress.org `readme.txt`; WordPress security and i18n sniffs (PHPCS) in CI.
