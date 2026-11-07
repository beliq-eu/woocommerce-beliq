# Changelog

## Unreleased

- An automatic invoice whose first attempt meets a beliq outage is tried again later. The first attempt runs in the status change as before. When beliq cannot be reached or answers that it is unavailable, an Action Scheduler action tries again at about 2, 5, 10, 14, 30 and 60 minutes, or later when beliq's `Retry-After` asks for it. A failure that may have run at beliq is tried at most 3 times within 15 minutes, and a rejection is not retried. The classes follow the beliq SDKs; see the README's "When beliq is unavailable".
- Every failure of the automatic invoice shows on the order, as an order note and in the beliq box, with the next attempt's time. Before, it went to the WooCommerce log only, and the box said that no invoice had been generated yet.
- A transport failure says where the request stopped (the connection could not be opened, the connect timed out, the request timed out, the connection was lost, TLS), and a beliq error carries the response's `Retry-After`.
- Two status changes of one order at once make one attempt: each attempt holds a database lock for its order.

## 0.1.0 (2026-11-05)

- The readme and the admin screens no longer call the output "compliant". The readme says the plugin helps with e-invoicing obligations but cannot guarantee legal compliance, as WordPress.org plugin guideline 9 requires, and links the dated list of rule sets beliq validates against.
- Totals sum the line nets as emitted. Each line net is rounded once and every sum is taken over the rounded values, so the invoice's line total (BT-106) and each VAT group's taxable amount equal the sum of the lines (BR-CO-10, BR-S-08). WooCommerce stores line nets past two decimals, and rounding their unrounded sum instead could land a cent away, which the validator rejects.
- `/v1/generate` is asked for the document's own media type. The endpoint returns the raw file by default but switches to a JSON envelope carrying the file base64-encoded as soon as the caller ranks `application/json` above the document type, so sharing the `Accept` header the JSON endpoints need would store that envelope in place of every invoice.
- Verified end to end against production `api.beliq.eu` on WordPress 7.1.4 and WooCommerce 11.2.2: 38 of 38 smoke checks, covering German XRechnung, French Peppol BIS, and German ZUGFeRD (hybrid PDF).
- The text domain is `beliq-e-invoicing`, matching the wp.org slug. A domain that does not match the slug is never imported into translate.wordpress.org, so no language pack could ever exist for it.
- beliq calls go through the WordPress HTTP API (`wp_remote_request`) rather than cURL directly, so a site's proxy configuration, `WP_HTTP_BLOCK_EXTERNAL` and the `http_request_args` filters apply to them.
- `Tested up to` is 7.1.
- A Composer install puts the plugin in `wp-content/plugins/woocommerce-beliq`, through `composer/installers` `^2.0`; without it the `wordpress-plugin` type landed in `vendor/`, where WordPress never looks. The site's `composer.json` has to allow that plugin (`config.allow-plugins`). `ext-curl` is only a development requirement now, since the plugin calls beliq through the WordPress HTTP API and only the tests use cURL.
- The Output setting resolves to XML on XRechnung and Peppol BIS. Neither has a hybrid PDF, so the API answered `output=pdf` for them with a 400 on every order. The setting's own label ("PDF (hybrid, where the format supports it)") already said this is what it means.
- Framework-agnostic core: invoice value objects, InvoiceMapper (EN 16931 category derivation, tax grouping, rounding, totals), BeliqClient over a cURL HTTP seam.
- WooCommerce order adapter: maps an order to the normalized invoice shape through a read-only seam, unit-tested without a WordPress runtime.
- Typed plugin settings with defaults and coercion.
- WooCommerce runtime: plugin bootstrap with the WordPress header and HPOS (`custom_order_tables`) compatibility declaration; a `WC_Integration` settings screen; `WcOrderData` / `WcLineData` wrappers over `WC_Order`; the `WooPluginConfigProvider` with `yes` / `no` checkbox coercion and the two order meta-key mappings (buyer VAT, buyer reference).
- Automatic generation on the configured order status, with the failure isolated from the transition; manual generate/regenerate from the order metabox and the WooCommerce order-action dropdown, with regeneration idempotency.
- Protected document storage in the uploads folder with a capability-checked download; i18n text domain and a WordPress.org `readme.txt`; WordPress security and i18n sniffs (PHPCS) in CI.
