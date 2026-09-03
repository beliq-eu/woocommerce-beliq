# woocommerce-beliq roadmap

A WooCommerce plugin that turns store orders into compliant EN 16931 e-invoices
(XRechnung, ZUGFeRD, Factur-X, Peppol BIS) through the beliq API. beliq produces
and checks the document; transmission, archiving, and tax-authority reporting
stay with the merchant.

This is the WooCommerce half of the reference PHP pair. The framework-agnostic
core (the beliq client + the order-to-EN 16931 mapper + its tests) is shared in
intent with the Shopware plugin; here it is a copy under a neutral namespace (see
the core-sharing decision below).

## Naming

- Repo / directory: `woocommerce-beliq` (matches the `<platform>-beliq` portfolio).
- Composer package: `beliq/woocommerce-beliq`, type `wordpress-plugin`.
- PHP namespace: `Beliq\WooCommerce\` for plugin classes, `Beliq\Core\` for the
  copied framework-agnostic core.
- Plugin display label: `beliq e-invoicing`.

## Locked decisions

1. **Copy the core under a neutral `Beliq\Core\` namespace; no shared package yet.**
   The mapper, client, value objects, and their tests are copied from the Shopware
   plugin and renamed `Beliq\Shopware\` to `Beliq\Core\`. The plugin ships a
   self-contained zip with no external Composer dependency and no namespace
   prefixing. A shared `beliq/beliq-php` package stays deferred until a third PHP
   consumer or real drift makes it worth the packaging and prefixing cost; the
   neutral namespace keeps that extraction a mechanical move. Any change to the
   copied core must be mirrored to `shopware-beliq`.
2. **Generation defaults to business orders, configurable.** Generate a structured
   invoice when the buyer looks like a business (VAT id present, or a billing
   company is set); the merchant can widen to all orders. B2C party data from
   checkout is often too thin to form a clean invoice, and the mandate demand is
   B2B, so the safe default is narrow.
3. **The plugin generates and stores; it does not transmit.** Output is a compliant
   document stored with the order. Peppol transmission, email delivery, and filing
   stay with the merchant.
4. **Standard-rated (`S`) is the correct, tested path.** A zero rate is a
   merchant-configured category (default `Z`). Reverse-charge / intra-community /
   export exemption reasons are a future per-connector enhancement (the beliq API
   accepts them; the plugin does not yet populate them).
5. **The wp.org slug is `beliq-e-invoicing`; the repo, the Composer package and
   the main file keep their own name.** wp.org derives the slug from the plugin's
   display name, the text domain has to match it or translate.wordpress.org never
   imports the strings, and a slug whose first term is `woocommerce` is a
   Guideline 17 trademark rejection. Two identities, deliberately.
6. **The version stays `0.1.0` until the publish pass, which is where `1.0.0` gets
   decided.** Every published beliq connector is 0.x (n8n 0.2.0, activepieces
   0.2.1, directus 0.2.3, beliq-mcp 0.3.1, beliq-cli 0.2.1, beliq-sevdesk 0.2.1,
   `@beliq/sdk` 0.3.1, `beliq` on PyPI 0.2.1), and beliq itself has not gone live,
   so a 1.x plugin would be the only 1.x thing in the portfolio and would claim
   more than the product does. Nothing is pinned to the number yet: no git tag, no
   wp.org listing, no Packagist listing. The publish pass already reopens the
   version metadata (`Stable tag`, the plugin header, the changelog date), so
   promoting to `1.0.0` there costs the same as now and can be decided against a
   live API. `Stable tag` and the header `Version` must always agree.

## Passes

### Pass 1: framework-agnostic core + order adapter (this pass)

- `src/Core/*`: the invoice value objects, `InvoiceMapper` (per-line VAT category
  derivation, `taxSummary` grouping by category+rate, EN 16931 rounding and totals
  BR-CO-15/17), `BeliqClient` (generate returns document bytes + header meta;
  validate/me return the JSON data; errors map to `BeliqApiException`) over an
  `HttpClient` seam. Copied from the Shopware plugin under `Beliq\Core\`.
- `src/Config/PluginConfig.php`: the typed settings value object plus the static
  `fromValues()` coercion, free of any WordPress runtime.
- `src/Order/{OrderData,LineData}.php`: a narrow read-only seam the adapter maps
  against, so the mapping runs and is tested without booting WordPress.
- `src/Order/WooOrderAdapter.php`: `OrderData` to the normalized `SourceOrder`.
  WooCommerce stores line totals net of tax, so a line's net is its total as-is;
  the rate is the one WooCommerce applied when the wrapper resolved it, otherwise
  it is recovered from the tax and net amounts. Products, shipping, and fees each
  become a line; coupons are not emitted (WooCommerce folds discounts into each
  line's net). Buyer party and business signal come from billing company / VAT id;
  the buyer reference (BT-10) is the Leitweg-ID meta, then a customer reference,
  then the order number.
- `tests/`: PHPUnit. The mapper and client tests carry over unchanged; the config
  and adapter tests assert coercion, category/rate derivation, totals, business
  detection, and the reference fallback against plain stubs (real assertions, no
  mocks returning themselves). A live smoke gated on `BELIQ_API_KEY` sends a mapped
  body to `/v1/generate` + `/v1/validate` and asserts the document validates.
- Repo skeleton: `composer.json` (type `wordpress-plugin`), `README`, `LICENSE`
  (MIT), `.gitignore`, `phpunit.xml`, scrub check, CI matrix 8.2/8.3/8.4.

Verified locally by driving the ported core and the adapter through the bootstrap
autoloader (this box's CLI PHP lacks the dom/xml/mbstring extensions PHPUnit 11
needs, so PHPUnit itself runs in CI). No WordPress is needed to build or test.

### Pass 2: WooCommerce runtime wiring (done)

- `woocommerce-beliq.php`: plugin bootstrap with the WordPress header
  (`Requires Plugins: woocommerce`, WC version headers), a self-contained PSR-4
  autoloader, the HPOS `custom_order_tables` compatibility declaration, and a
  WooCommerce-missing admin notice.
- `Plugin`: wires the integration, the status trigger, and the admin surfaces
  once WooCommerce is loaded.
- `WcOrderData` / `WcLineData`: the `OrderData` / `LineData` wrappers over a real
  `WC_Order` (line net from `get_total()`, dominant rate from `WC_Tax`, buyer VAT /
  reference from the configured order meta keys, all through the order CRUD API for
  HPOS).
- `InvoiceIntegration` (a `WC_Integration`): the settings screen for the beliq
  connection, seller legal details, payment means, and generation options, plus
  the two WooCommerce-only meta-key settings (buyer VAT, buyer reference).
- `WooPluginConfigProvider`: reads the stored settings, normalizes the `yes`/`no`
  checkbox values, and calls `PluginConfig::fromValues`; the coercion is unit-tested
  through the static `fromRawSettings()` seam.
- `OrderStatusTrigger`: fires on `woocommerce_order_status_changed`, runs only on
  the configured status, and never throws out of the handler (a generation failure
  must not break the transition), logging through `wc_get_logger()`.
- `InvoiceGenerator` + `DocumentStore`: generate through the beliq client, store the
  bytes in a protected uploads directory (with a deny rule), record the location in
  order meta, and serve downloads through a capability-checked, nonce-verified,
  path-validated endpoint.
- `OrderMetabox` + `OrderActions`: the order-screen box with status and download,
  a manual (re)generate posted to `admin-post.php`, and the native
  `woocommerce_order_actions` entry; the automatic trigger is idempotent (it does
  not overwrite), a manual regenerate forces a rebuild.
- Two new `PluginConfig` fields (`buyerVatMetaKey`, `buyerReferenceMetaKey`); i18n
  text domain `woocommerce-beliq` with a `languages/woocommerce-beliq.pot` template
  and a WordPress.org `readme.txt`; a PHPCS ruleset running the WordPress security
  and i18n sniffs over the runtime code, added to CI as a separate job.

Verified offline the same way as Pass 1 (this box's CLI PHP has no dom/mbstring, so
PHPUnit and PHPCS run in CI): every file lints clean with `php -l`, and the new
`WooPluginConfigProviderTest` covers the checkbox coercion and the meta-key mapping
against plain arrays. The runtime classes are never autoloaded by the offline suite.

### Pass 3: live smoke + store submission

Smoke against a Dockerized WordPress + WooCommerce with the plugin installed and a
local beliq api/engine: place a B2B order, transition it to the trigger status, and
assert the full path (hook -> wrapper -> adapter -> mapper -> beliq API -> stored
document) produces a green EN 16931 document, that HPOS order meta round-trips, and
that the admin download works. Add a German XRechnung and a non-German Peppol BIS
live case. Then the operator-gated WordPress.org submission (screenshots, stable
tag, review).

The smoke is DONE and green: a committed, reproducible harness under `smoke/`
(MariaDB + WordPress + WooCommerce + the plugin, reaching a host beliq api/engine
via `host.docker.internal`) runs 38/38 checks across German XRechnung, French
Peppol BIS, and German ZUGFeRD (hybrid PDF), plus the business-only skip,
idempotency, and the download capability gate. HPOS meta round-trip is verified
against the `wc_orders_meta` table; the admin download is verified end to end with
a real authenticated HTTP request. See `PASS-3-SMOKE-ROADMAP.md` and `smoke/README.md`.
The WordPress.org submission stays operator-gated (screenshots + SVN + review).

### Pass 4: the wp.org submission gate (done)

Plugin Check reported 95 findings, 94 errors, across four `plugin_repo` checks, and
an error there blocks submission before a reviewer sees the plugin. The run is clean
now. The text domain is `beliq-e-invoicing` (the wp.org slug, which the repo and the
Composer package do not share), beliq calls go through the WordPress HTTP API, and
`Tested up to` is 7.1. Two blockers named in the 2026-08-30 publish-readiness audit
did not survive being run against the tool. Full write-up, including what the audit
missed, in `PASS-3-SMOKE-ROADMAP.md` section 3.4; the gate itself is
`plugin-check/run.sh`.

### Pass 5: the wp.org gate runs in CI and weekly (done)

Pass 4 cleared Plugin Check and nothing kept it clear. `ci.yml` gains a
`plugin-check` job running `plugin-check/run.sh --ignore-calendar`, and
`wporg-currency.yml` runs the same script weekly without that flag.

**`wp plugin check` exits 0 whatever it finds**, in every output format including
`strict-table`, `strict-csv` and `strict-json`, verified with a confirmed ERROR
present. So `run.sh` as first committed printed findings and exited 0: a gate
that surfaces and never blocks. The verdict now comes from a second run with
`--format=strict-json`, which prints a JSON array when there are findings and the
plain success line when there are none. **"Did not parse as JSON" therefore has
to fail**, or a wp-cli crash reads as clean.

The split is the point. `outdated_tested_upto_header` compares `readme.txt`
against whatever wordpress.org calls current, so it starts failing on a WordPress
release with nothing changed here, and it would redden PRs that never touched the
readme. It is dropped from the PR gate and owned by the weekly workflow, which
runs the same script rather than a second copy of the rule. Scheduled fires slip
(GitHub delays daily crons by 11 to 13 hours in measured cases); weekly is sized
for that. A failed scheduled run notifies the account that last touched the cron,
which is the only thing watching it.

Falsified in both directions before being trusted, all four arms as designed:

| Tree | full | `--ignore-calendar` |
|---|---|---|
| clean | 0 | 0 |
| `Tested up to` back to 6.7 | **1** | 0 |
| one text-domain call reverted | **1** | **1** |

Plus the parser's own branches: an error row exits 1, a warning-only array exits
0, the success line exits 0, and both a wp-cli error string and empty output exit
1 rather than reading as clean. An unknown argument exits 2.

## Operator-gated (post-go-live)

- WordPress.org plugin directory submission and manual review, and/or a Packagist
  listing. Needs a live beliq API for review screenshots and a test store.
- Live-key smoke once a `BELIQ_API_KEY` and live/staging API exist.

## Conventions

- GitHub `beliq-eu`; commit identity `beliq <hello@beliq.eu>`; push pinned with
  `GH_TOKEN=$(gh auth token --user beliq-eu)`; active gh back to `tobias-dev`.
- No em-dash, no buzzwords; comments and docs describe present state.
- Copy stays on "generate / validate a compliant document," never
  "send / file / submit."
