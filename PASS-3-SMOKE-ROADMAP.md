# woocommerce-beliq - Pass 3 (live Docker smoke + wp.org submission)

Living roadmap for D8.2 Pass 3. Passes 1 and 2 are merged and green (see
`ROADMAP.md`). This pass proves the WordPress runtime path end to end against a
local beliq api + engine, then preps the operator-gated WordPress.org submission.

## Goal (from the roadmap)

Dockerized WordPress + WooCommerce with the plugin installed, pointed at a local
beliq api/engine: place a B2B order, transition it to the trigger status, and
assert the full path

```
woocommerce_order_status_changed  ->  OrderStatusTrigger
  ->  WcOrderData (WC_Order seam)  ->  WooOrderAdapter  ->  Core\InvoiceMapper
  ->  Core\BeliqClient -> /v1/generate  ->  DocumentStore
```

stores a green EN 16931 document, that HPOS order meta round-trips, and that the
admin download works. Cases: German XRechnung (XML) + non-German (French) Peppol
BIS (XML) + the default German ZUGFeRD hybrid PDF. Then the operator-gated
WordPress.org submission (screenshots, `Stable tag`, review).

## Sub-passes

### 3.1 - Local beliq backend (DONE)

Stood up the local api + engine and confirmed both target profiles validate
green with a real API key (no `ALLOW_UNAUTHENTICATED` bypass; the api's dotenv
keeps it `false`, matching the Shopware Pass 3 setup).

- Postgres (`beliq-dev-postgres`, :5432) + Redis (:6379): the `beliq-infra/local`
  dev stack, migrations current (`beliq-db yarn db:migrate`).
- Engine: `beliq-engine:smoke` image built from `beliq-engine/Dockerfile`, run on
  :8000 with `ALLOW_UNAUTHENTICATED=true` (health: java/saxon/mustang/verapdf all
  green). Built from local `main`, so it carries `bq-engine#119`.
- API: `beliq-api` host process (`yarn dev`) on :3000, `ENGINE_URL=http://localhost:8000`,
  against the dev DB. Listens on the docker bridge IPs too, so a container reaches
  it via `host.docker.internal`.
- API key: minted a real key for the idempotent demo org (`seedDemoOrg`) via
  beliq-db's own `apiKeyService.create` + `hashApiKey` (helper deleted after use).
- Verified with raw curl: German XRechnung -> `valid=true`, cii/xrechnung, 0 errors;
  French Peppol BIS -> `valid=true`, ubl/peppol, 0 errors. Buyer needs an email
  (BT-49 electronic address) or generate 422s on `PEPPOL-EN16931-R010`.

### 3.2 - WordPress + WooCommerce Docker smoke (DONE)

Ran green: **38/38 checks passed** across all three format cases plus the
business-only skip, idempotency, and the download capability gate. HPOS is
enabled and the invoice meta was confirmed to round-trip by reading the HPOS
`wc_orders_meta` table directly (not just the in-memory order object). The
`admin download works` claim was closed with a real authenticated HTTP request:
logged in as admin, scraped the nonced download link off the HPOS order-edit
screen, and `GET`ed it to a `200` with `Content-Disposition: attachment;
filename="invoice-11.xml"`, `Content-Type: application/xml`, 6012 bytes; the
downloaded bytes re-validated green (cii/xrechnung, 0 errors).

Per-case green: German XRechnung -> cii/xrechnung 0 errors; French Peppol BIS ->
ubl/peppol 0 errors; German ZUGFeRD hybrid PDF -> stored as `%PDF`, and the same
order re-mapped to XML re-validated green (cii). WooCommerce 10.9.1, WordPress
latest on PHP 8.3, MariaDB 11.

A committed, reproducible harness under `smoke/`:

- `smoke/docker-compose.yml`: MariaDB + WordPress (php-apache) + a `wpcli` service,
  the plugin bind-mounted into `wp-content/plugins/woocommerce-beliq`. Reaches the
  host api via `host.docker.internal` (`extra_hosts: host-gateway`).
- `smoke/run.sh`: brings the stack up, installs WP core + WooCommerce, enables
  HPOS, activates the plugin, then runs `smoke.php` and tears down.
- `smoke/smoke.php`: `wp eval-file` scenario. For each case it configures the beliq
  settings option, creates a taxed product + a B2B order, transitions it to the
  trigger status, then asserts: a document is stored; the order meta round-trips
  after a fresh reload (HPOS); the stored bytes re-validate green via `/v1/validate`;
  the download resolves + is capability-gated. Also covers the business-only skip
  and auto-vs-manual idempotency.
- `smoke/README.md`: prerequisites (3.1 backend up) + how to run.

Cases: German XRechnung (xml), French Peppol BIS (xml), German ZUGFeRD (hybrid pdf).

### 3.3 - WordPress.org submission (OPERATOR-GATED)

The `readme.txt`, screenshots, and version metadata are prepared in-repo; the
actual SVN commit + review is the operator's (needs a wp.org account).

Done in-repo:
- `readme.txt` is wp.org-valid, its `Stable tag: 0.1.0` matches the plugin header
  `Version: 0.1.0`, and it carries the external-services disclosure. `Requires at
  least 6.4`, `Requires PHP 8.2`.
- The two submission screenshots are captured and named to the wp.org convention
  in `tmp/`: `screenshot-1.png` (the Integrations settings screen, API key masked,
  no editable API base URL) and `screenshot-2.png` (the order "beliq e-invoice"
  box, highlighted, with Download + Regenerate). `tmp/` is untracked and must not
  be committed; the PNGs go to SVN `/assets`, not the plugin tree.
- `readme.txt` has a `== Screenshots ==` section whose two captions match that
  file order.
- Plugin header `WC tested up to` bumped to `10.9` (the smoke ran WooCommerce
  10.9.1).

Operator steps that remain:
1. Copy `tmp/screenshot-1.png` and `tmp/screenshot-2.png` into the wp.org SVN
   `/assets` directory (not `trunk`).
2. Bump `Tested up to` (WP) in `readme.txt` to the WordPress version the store
   reports (Dashboard > Updates, or the store footer). The smoke ran WordPress
   latest on an unpinned image, so the exact number has to be read off the store;
   it is not knowable from the repo.
3. Flip `CHANGELOG.md` `0.1.0 (unreleased)` to the release date at tag time.
4. Submit to the plugin directory for review, then on approval SVN-commit `trunk`
   + tag `0.1.0` and set the wp.org `Stable tag`.

## Decisions

- **Real API key, not the unauth bypass.** More faithful (exercises the plugin's
  `X-API-Key` path) and matches how Shopware Pass 3 ran. The key is a local dev
  secret, never committed.
- **Green is asserted by re-validating, not by trusting a 200 from generate**
  (though generate already validates internally and 422s a non-green document).
  The XML cases post the stored bytes to `/v1/validate`. `/v1/validate` handles
  XML, not a hybrid PDF, so the PDF case re-maps the same order through the
  plugin's adapter + mapper to the equivalent CII XML and validates that.
- **Commit the harness** (Shopware ran it ad-hoc). The task is explicitly "build a
  Dockerized WP+WC smoke"; a committed, re-runnable harness is the deliverable and
  documents the exact path for the next connector.

## Conventions

GitHub `beliq-eu`; commit identity `beliq <hello@beliq.eu>`; push pinned with
`GH_TOKEN=$(gh auth token --user beliq-eu)`; active gh back to `tobias-dev` after.
No em-dash, no buzzwords; docs describe present state.
