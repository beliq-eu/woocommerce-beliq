# woocommerce-beliq

A WooCommerce plugin that turns store orders into EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the [beliq](https://beliq.eu) API. It generates and validates the documents; it does not send, file, or submit them anywhere.

## Status

Version 0.2.2 is on Packagist as [`beliq/woocommerce-beliq`](https://packagist.org/packages/beliq/woocommerce-beliq), built from the tag `v0.2.2`. It has no WordPress.org listing yet. What is built:

- The plugin boots as a WordPress plugin, declares HPOS compatibility, exposes a settings screen under WooCommerce > Settings > Integration, generates a document when an order reaches the configured status, and stores it for a capability-checked download from the order screen.
- The live smoke in `smoke/` drives a real WordPress + WooCommerce store through the whole order-to-document path. On 2026-11-05 it passed 38 of 38 checks against the production beliq API on WordPress 7.1.4 and WooCommerce 11.2.2.
- The official wp.org Plugin Check runs on the distribution in CI (`plugin-check/run.sh --ignore-calendar`, which fails on any error) and weekly with the calendar-driven check included (`.github/workflows/wporg-currency.yml`).

Next is the WordPress.org submission.

## Install

With Composer, from the directory that holds your WordPress project's `composer.json`:

```
composer require beliq/woocommerce-beliq
wp plugin activate woocommerce-beliq
```

The package requires `composer/installers`, which puts the plugin in `wp-content/plugins/woocommerce-beliq/`, or wherever the project's `installer-paths` sends `type:wordpress-plugin` (Bedrock uses `web/app/plugins/`). Composer asks once whether to allow the `composer/installers` plugin; allow it, or run `composer config allow-plugins.composer/installers true` first. WooCommerce must be active. The settings are under WooCommerce > Settings > Integration.

## How it works

- Settings live in a `WC_Integration` (`src/Integration/InvoiceIntegration.php`); `WooPluginConfigProvider` reads them into the typed `PluginConfig`, coercing WooCommerce's `yes` / `no` checkbox values.
- `OrderStatusTrigger` fires on `woocommerce_order_status_changed`; when the order reaches the configured status it hands the order to `InvoiceAttempts`, and it never lets a failure break the transition (errors go to the WooCommerce log, source `beliq`).
- `InvoiceAttempts` (`src/Retry/`) makes the first attempt in the status change and, when beliq cannot be reached or is unavailable, the later ones from an Action Scheduler action (hook `beliq_retry_invoice`, group `beliq`). See "When beliq is unavailable" below.
- `WcOrderData` / `WcLineData` wrap a `WC_Order` behind the `OrderData` / `LineData` seam (line net from `get_total()`, rate from `WC_Tax`, buyer VAT and reference from the configured meta keys), so `WooOrderAdapter` maps against plain data.
- `InvoiceGenerator` maps the order, applies the business-only gate, calls beliq, and hands the bytes to `DocumentStore`, which writes them to a protected uploads subdirectory and records the location in order meta.
- `OrderMetabox` shows the status and a download button; `OrderActions` serves the capability-checked download and a manual (re)generate, and adds the native WooCommerce order-action entry.

## When beliq is unavailable

When an automatic invoice's first attempt fails, the failure goes on the order, and a failure a wait can help is tried again later. What is retried, and when (`src/Core/Retry/RetrySchedule.php`), follows the beliq SDKs (`@beliq/sdk` 0.7.1, `beliq` 0.7.0) with two departures: the first attempt's own timeout is retried as a request that may have run (the SDKs never retry their own timeout, but the order request's 30 s can be shorter than beliq's own deadlines), and a TLS failure is never retried (the Node SDK retries it by count). A failure that is not retried, or that outlasts its retries, leaves the order without an automatic invoice; the beliq box shows it next to "Generate now":

- An outage a wait can outlast is tried at about 2, 5, 10, 14, 30 and 60 minutes after the first attempt, or later when beliq's `Retry-After` asks for longer: a 503 `DATABASE_UNAVAILABLE`, a 502 or 503 without a beliq error code, and a connection that could not be opened.
- A failure that may have run at beliq is tried at most 3 times, and only within the first 15 minutes, however late the queue runs: `ENGINE_UNAVAILABLE` or another coded 502 or 503, a 429 other than `QUOTA_EXCEEDED`, a connection lost after the request was sent, and the first attempt's own timeout. beliq does not charge a repeat of a request it charged in the last 15 minutes, with a key of the same mode, up to 10 repeats ([Repeated requests](https://docs.beliq.eu/api-reference/errors/#repeated-requests-are-not-charged-again)); a later repeat of a request whose answer was lost would be charged again.
- The first attempt runs in the status change with a 30 s timeout; the later attempts have the 90 s of the SDKs. A later attempt's own timeout is final, since beliq may still be working on the document.
- An outage failure after one that may have run (a lost connection, then a refused one, say) still gets the 30 and 60-minute slots. If the earlier request was charged and its answer lost, an attempt past 15 minutes that succeeds is charged again: one more unit, for an invoice that would otherwise not be made.
- A slot that passed while beliq asked for a longer wait, or while no runner ran, is skipped rather than run straight after. A `Retry-After` that would push an attempt past the 60-minute slot ends the retries.
- Nothing else is retried: a 4xx would send the same order data to the same answer, and a TLS failure or a malformed base URL is not fixed by waiting.

Each failure goes on the order, as an order note and in the beliq box, with the next attempt's time or "No further automatic attempt". A stored invoice clears it. Each automatic attempt holds a database lock (`GET_LOCK`) for its order and queues the next attempt before it calls beliq, so a process killed mid-call leaves the next attempt in place and two automatic attempts never run for one order at once. A manual "Generate now" does not take the lock. A database without `GET_LOCK` runs the attempts without it. The slots and timeouts are beliq's choice, sized from its own outages; no standard sets them.

Action Scheduler runs from WP-Cron, which runs on page loads, so on a quiet shop an attempt can run late. A system cron that calls `wp-cron.php` or `wp action-scheduler run` every minute keeps it on time. A runner killed mid-call leaves its Action Scheduler claim open, and Action Scheduler starts no new batch until it releases that claim (after 5 minutes by default).

## Development

```
composer test          # PHPUnit (offline: mapper, client, config, provider, adapter)
composer phpcs          # WordPress security + i18n sniffs over the runtime code, src/Core included
composer phpstan        # PHPStan at level 8 over the plugin file and src/, against the WordPress and WooCommerce stubs
composer scrub:check    # no em-dash
composer archive:check  # git archive HEAD (what Packagist ships) = the wp.org distribution + composer.json, README.md, CHANGELOG.md
```

The offline suite needs no WordPress: the runtime classes reference WooCommerce symbols only inside method bodies, so the tests that exercise the pure logic (`PluginConfig`, `WooPluginConfigProvider`, the adapter, the mapper, the client) never load them. A live smoke against the beliq API is gated on `BELIQ_API_KEY`; `smoke/run.sh retry` runs the later-invoice cases in a real store against a stub on the host, with no key.
