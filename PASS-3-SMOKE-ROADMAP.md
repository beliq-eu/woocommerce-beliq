# woocommerce-beliq - Pass 3 (live Docker smoke + wp.org submission)

`status: live, next: 3.3 step 4, the operator uploads the zip rebuilt after the guideline 9 rewording; both screenshots need re-capturing before the SVN publish in step 5`

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
  least 6.5`, `Requires PHP 8.2`.
- The two submission screenshots are captured and named to the wp.org convention
  in `tmp/`: `screenshot-1.png` (the Integrations settings screen, API key masked,
  no editable API base URL) and `screenshot-2.png` (the order "beliq e-invoice"
  box, highlighted, with Download + Regenerate). `tmp/` is untracked and must not
  be committed; the PNGs go to SVN `/assets`, not the plugin tree.
- `readme.txt` has a `== Screenshots ==` section whose two captions match that
  file order.
- Plugin header `WC tested up to` is `11.1` (the production smoke in 3.6 ran
  WooCommerce 11.1.0).
- Plugin Check is clean (3.4).

**The wp.org slug is `beliq-e-invoicing`, not `woocommerce-beliq`.** wp.org derives
it from the plugin's display name, and a slug whose first term is `woocommerce` is a
Guideline 17 trademark rejection. The repo, the Composer package and the main file
keep their own name; only the distributed plugin identity changes.

#### Remaining operator steps

The publish waited on a **working free tier**, not on the API itself: the wp.org
reviewer tests functionality and the readme promises "the free tier is enough to
evaluate the plugin", so a reviewer who cannot sign up and generate fails the
submission. **That gate is now discharged, proven against production on
2026-09-07** (step 1 below carries the evidence).

Whether to submit ahead of the public launch announcement is a separate call, and
it is the operator's.

Already done:
- The in-repo prep (this pass) is merged.
- The submitter's WordPress.org account is `@beliq`, matching `Contributors: beliq`.

Then, in order:

1. **Confirm the live path. DONE 2026-09-07**, walked as a first-time reviewer
   would in a clean browser with no prior session.

   - **Signup works, card-free.** `/auth/register` takes email + a 10-character
     password; the organization name is optional and is auto-generated when left
     blank. The Cap.js captcha is invisible and self-solving, so it is not a
     barrier to a reviewer. Terms and a business affirmation are both required
     checkboxes, and the Terms of Service and Privacy Policy links resolve.
   - **Email delivery works.** Registration returns an opaque `verifyToken` in the
     URL and sends a separate 6-digit code, 15-minute expiry, limited attempts,
     with a resend path. The token alone does not verify the account. The code
     arrived and verification landed on `/auth/verified`.
   - **The org lands on Free automatically.** `GET /v1/me` reports
     `plan: {id: null, name: "Free"}`, `livemode: true`, `rateLimitPerMinute: 10`
     and `quota.limit: 20`, which matches `FREE_TIER` in `beliq-types` exactly. No
     card, no manual provisioning.
   - **A free-tier live key generates a green document.** One `POST /v1/generate`
     with `standard: xrechnung`, `output: xml` returned a 5,949-byte
     `rsm:CrossIndustryInvoice` customized
     `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0`.
     Posting those bytes back to `/v1/validate` returned `valid: true`, **0 errors
     and 0 warnings** (CII, schematron 1.3.16), so green is asserted by
     re-validating rather than by trusting the 200, the same rule 3.2 uses.
   - **Quota metering is real and `/v1/me` is free.** The two calls moved
     `quota.used` from 0 to 2; three `/v1/me` calls moved it not at all, which is
     why every beliq connector uses `/v1/me` as its credential test.

   The invoice used was the **prefilled sample the zapier connector ships**, so
   this also re-confirms against production the "Verified against POST
   /v1/generate" comment in `tools/zapier-beliq/src/creates/generateInvoice.ts`.

   Account: `wporg-test@beliq.eu`, org slug `wporg-reviewer-test-51a6a3`, a
   disposable evaluation org. Deleted 2026-09-19, together with its API key.

   This step alone does not cover the full order-to-invoice path through WordPress
   against production. 3.6 covers it: `smoke/run.sh` pointed at `api.beliq.eu`.
2. **Finalize version metadata. DONE 2026-09-18.**
   - `Tested up to: 7.1` against WordPress 7.1.1, the current release. The full
     `plugin-check/run.sh` run (without `--ignore-calendar`, so
     `outdated_tested_upto_header` is checked) reported 0 errors and 0 warnings on
     2026-09-18 with Plugin Check 2.1.0.
   - `WC tested up to: 11.1` against WooCommerce 11.1.1, the current release.
   - `CHANGELOG.md` carries `0.1.0 (2026-09-08)`.
   - `Stable tag: 0.1.0` matches the plugin header `Version: 0.1.0`.
3. **Build the submission zip. DONE 2026-09-19**, rebuilt after the 3.7 rewording
   from `60b5dee` as `tmp/beliq-e-invoicing-0.1.0.zip`, sha256
   `c16aa5fcf2aa79e53ff906dcb8ce66b4c70947dc39bf24b771b5018dd8d472c6`, 38,656
   bytes. It replaces the 2026-09-18 build from `3c75063`, which still said
   "compliant". It is `git archive HEAD` over the `DIST` set, so it holds only
   committed files: 28 files, no dotfiles, and all 25 PHP files pass `php -l`.
   The full `plugin-check/run.sh` run on that tree reported 0 errors and 0
   warnings on 2026-09-19 (WordPress 7.1.1). A later commit that
   changes only files outside `DIST` does not make it stale. Plugin runtime only,
   self-contained autoloader, no Composer install needed:
   - Include: `woocommerce-beliq.php`, `src/`, `languages/`, `readme.txt`, `LICENSE`.
     This is the `DIST` array in `plugin-check/run.sh`, which stages exactly that set.
   - Exclude: `tests/`, `smoke/`, `plugin-check/`, `tmp/`, `.github/`, `phpunit.xml`,
     `phpcs.xml`, `composer.json`/`composer.lock`, `ROADMAP.md`,
     `PASS-3-SMOKE-ROADMAP.md`, `.git/`, `vendor/`.
   - The ZIP's top-level directory must be `beliq-e-invoicing`.
4. **Submit for review** at `https://wordpress.org/plugins/developers/add/`, which
   bounces through `https://login.wordpress.org/` if you are not signed in (checked
   2026-09-03). No company, no fee. Per the developer FAQ (read 2026-09-19), every
   submission gets an initial review within four weeks. A small plugin with correct
   code is approved within fourteen days of that review. The slug is not shown
   before upload: the confirmation email names it, and it can be changed once
   after submitting. The zip can also be replaced from the submission page until
   the review starts.
5. **On approval, SVN publish.** Check out the assigned repo
   (`https://plugins.svn.wordpress.org/beliq-e-invoicing/`):
   - Put the plugin files in `/trunk`.
   - Put `screenshot-1.png` + `screenshot-2.png` in `/assets`, plus an icon/banner
     if desired. Assets live in `/assets`, never in `/trunk`. **Re-capture both
     first:** the copies in `tmp/` show the pre-3.7 wording (the settings
     description and "A compliant e-invoice is stored for this order."), which the
     Compliance Disclaimers page counts as a claim too. `screenshot-2.png` needs a
     stored invoice, so it needs the `smoke/` stack and an API key.
   - `svn copy trunk tags/0.1.0`, confirm `Stable tag: 0.1.0`, then `svn commit`.
6. **Verify the live listing.** Screenshots and description render; a fresh install
   against production `api.beliq.eu` generates a green invoice end to end.

### 3.4 - Plugin Check, the wp.org submission gate (DONE)

Plugin Check has been mandatory since 2024-10-01: an error in its `plugin_repo`
category blocks the submission before a reviewer sees the plugin. Run it with
`plugin-check/run.sh` (see that directory's README). Against the distribution on
WordPress 7.1 it reported **95 findings, 94 of them errors**, from four checks:

| Check | Findings | What |
|---|---|---|
| `i18n_usage` | 78 | text domain `woocommerce-beliq` against the slug |
| `plugin_review_phpcs` | 8 | 7 raw cURL calls, 1 `readfile()` |
| `late_escaping` | 7 | `ExceptionNotEscaped` on values that never reach output |
| `plugin_readme` | 1 | `Tested up to: 6.7` against core 7.1 |
| `plugin_header_fields` | 1 (warning) | the `Text Domain` header against the slug |

All four are `plugin_repo`. The run is now clean, including `--include-experimental`.

**Two of the blockers named in the 2026-08-30 audit are not blockers.** Neither
survived being run against the tool:

- **`defined('ABSPATH')` on the 24 files under `src/`.** `direct_file_access` does
  exist and does fire, but only on a file with side effects: dropping a
  `<?php echo "hello";` into the plugin produces `missing_direct_file_access_protection`
  immediately, while every `src/` file (all of them a namespace plus a class
  declaration) produces nothing. 24 guard lines would have been noise.
- **The `woocommerce-` trademark.** Real, but a **warning**, not an error, and it is
  keyed on the slug. It is gone by virtue of submitting as `beliq-e-invoicing`.

**What the audit missed:** the cURL calls and the `readfile()`, 8 errors from
`plugin_review_phpcs`, and the 7 escaping errors. It looked at `i18n`, `ABSPATH` and
the readme header and stopped.

#### The fixes

- **Text domain -> `beliq-e-invoicing`** across 78 call sites, the plugin header,
  `phpcs.xml.dist`, and `languages/woocommerce-beliq.pot` -> `beliq-e-invoicing.pot`.
  A text domain that does not match the slug is never imported into
  translate.wordpress.org, so no language pack could ever exist.
- **cURL -> the WordPress HTTP API.** `Beliq\WooCommerce\Http\WpHttpClient` implements
  the existing `HttpClient` seam over `wp_remote_request`, so a site's proxy
  configuration, `WP_HTTP_BLOCK_EXTERNAL` and the `http_request_args` filters reach
  beliq calls. This is a review guideline, not a lint opinion: raw cURL bypasses all
  of it. The connect bound PR #11 added survives the move for free: `WP_Http` passes
  only `timeout` to Requests, whose own `connect_timeout` default is 10s.
  `BeliqClient`'s `$http` parameter loses its default, because the framework-agnostic
  core can no longer name a transport. `CurlHttpClient` moves to
  `tests/Support/`, where `LiveGenerateSmokeTest` still needs it: PHPUnit runs
  outside WordPress and has no `wp_remote_request`. Its connect-timeout test moves
  with it.
- **`readfile()` stays**, annotated. `WP_Filesystem` has no streaming read, and the
  `get_contents()` + `echo` alternative was tried against the tool: it trades
  `file_system_operations_readfile` for `OutputNotEscaped` on bytes that cannot be
  escaped.
- **The escaping errors are annotated, not escaped.** The values reach
  `wc_get_logger()` and nothing else; the admin notice `OrderActions::renderNotice()`
  prints is a fixed translated string run through `esc_html()`. Escaping at the throw
  would corrupt the log line and drag WordPress into the framework-agnostic core. Each
  annotation names the invariant so the next person who renders `getMessage()` sees
  it. `phpcs:ignore` was verified to be honoured, and a plugin-root `phpcs.xml` was
  verified **not** to be: Plugin Check uses its own ruleset.

#### `Tested up to: 7.1` is a checked claim

wp.org forces the header to the current release, so the question is what backs it.
`plugin-check/run.sh --with-surfaces` installs WordPress 7.1 and WooCommerce 11.0.1,
activates the plugin, and asserts the integration registers, the order-status trigger
and both admin-post endpoints carry **this plugin's** callbacks, HPOS compatibility is
declared, and the text domain matches the slug. Ten checks, all green; with the plugin
deactivated eight of them fail, so the script is not vacuously passing.

That covers loading and hooking, not generating. `WC tested up to` follows the full
order-to-invoice smoke instead, so it moved to `11.1` only when 3.6 re-ran that smoke
against production on WooCommerce 11.1.0.

### 3.5 - The gate runs in CI and weekly (DONE)

3.4 cleared Plugin Check; nothing kept it clear. `ci.yml` runs
`plugin-check/run.sh --ignore-calendar` on every PR and push, and
`wporg-currency.yml` runs the same script weekly without the flag. Both details,
the verdict and the split, are in `plugin-check/README.md`; the falsification
table is in `ROADMAP.md` "Pass 5".

The one fact worth repeating here, because it invalidates the obvious CI step:
**`wp plugin check` exits 0 whatever it finds**, in every output format. `run.sh`
as committed in 3.4 printed the findings and exited 0.

### 3.6 - The smoke against production, and the bug only it could find (DONE 2026-09-07)

Re-ran the committed harness with `BELIQ_BASE_URL=https://api.beliq.eu` and a
free-tier live key, on **WordPress 7.1 + WooCommerce 11.1.0**. First run: **9 of
38 failed**. Second run, after the fix: **38 of 38**.

**Every stored invoice was the API's JSON envelope instead of the invoice.**
`/v1/generate` returns the raw file by default and switches to a JSON envelope
carrying the file base64-encoded as soon as the caller ranks `application/json`
above the document's own media type. `BeliqClient::authHeaders()` set
`Accept: application/json` for all three endpoints. `me` and `validate` do want
JSON; `generate` does not. All three formats stored an 8 to 54 KB JSON blob, and
the ZUGFeRD case stored it under a `.xml` extension.

**Why it survived to production.** The transport changed after this smoke last
ran. Pass 4 swapped `CurlHttpClient` for `WpHttpClient` to clear the wp.org gate
and re-ran Plugin Check and the unit tests, not the order-to-invoice path. The
`Accept` header was covered by nothing, and the local api the July run used
predates the JSON mode, so the header was inert when it was written and became
load-bearing when the API grew content negotiation. `prefersJsonEnvelope` in
beliq-api even documents that callers opt in by accident.

**The generalisable half, and it is the whole reason this pass exists: a green
suite is green about the transport it ran on.** Swapping the transport of a client
invalidates every test above it that did not assert on the wire, and the unit
tests here inject a fake `HttpClient`, so they could not see the header either.
The guard is now `testGenerateAsksForTheDocumentAndNeverTheJsonEnvelope`, which
asserts the header for both `xml` and `pdf` and fails when the shared header is
restored. Mirrored to `shopware-beliq` under locked decision 4, where the same
defect sat unobserved because its smoke has only ever run against a local api.

Two harness defects the run also exposed, both now fixed:

- **The smoke was not re-runnable.** Its two capability-gate users were created
  with `wp_insert_user`, which returns `WP_Error('existing_user_login')` on a
  second run against a stack that was not torn down. Both capability checks
  failed on that rather than on capabilities, which reads exactly like a
  WooCommerce 11 permissions regression and is not one. Pass 3's deliverable was
  a re-runnable harness, so this was the harness failing its own goal.
- **`run.sh` preflighted a hardcoded `localhost:3000`** while passing
  `BELIQ_BASE_URL` through to the container, so the production run this roadmap
  already contemplated could not get past the preflight. It now probes whatever
  the base URL names.

`WC tested up to` moves to **11.1** on the strength of this run rather than on the
surfaces test alone, which checks loading and hooking but not generating.

### 3.7 - Audit against the wp.org submission docs (2026-09-19, wording fixed; screenshots due before step 5)

We read the three documents the submission page points to and checked the
distribution against each: the
[developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/),
the [detailed guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)
(last updated 2026-03-11), and the [Plugin Check](https://wordpress.org/plugins/plugin-check/)
page. We also read the
[Compliance Disclaimers](https://developer.wordpress.org/plugins/wordpress-org/compliance-disclaimers/)
page, which the guidelines link and which spells out guideline 9.

**Fixed 2026-09-19: guideline 9, "implying that a plugin can create, provide,
automate, or guarantee legal compliance".** The Compliance Disclaimers page asks for the
readme, the descriptions and the assets (screenshots included) to say the plugin
*assists* with compliance. It also asks for a note that no plugin can guarantee
compliance. For a service it asks the readme to say the service carries the
claim, with a dated link to the evidence. Reviewers warn first, then close the
plugin after 60 days. "compliant" appears in six shipped places:

- `readme.txt`: the short description, the first Description paragraph, and the
  External services paragraph.
- The `Description:` header in `woocommerce-beliq.php`.
- The settings screen description in `src/Integration/InvoiceIntegration.php`.
- The order metabox text in `src/Admin/OrderMetabox.php` ("A compliant e-invoice
  is stored for this order."), plus both strings in
  `languages/beliq-e-invoicing.pot`.

The fix (`60b5dee`) drops the word from all six, plus the GitHub `README.md` and
`composer.json`. The metabox now says "A validated e-invoice is stored for this
order." The readme gains a paragraph saying no plugin can guarantee legal
compliance, and it links
https://docs.beliq.eu/compliance/validation-artifacts/ as the service's dated
evidence: that page lists the rule sets in production with their versions and
dates. The readme's "Integrations" tab name is now "Integration", as the screen
shows it. Both screenshots still show the old strings; step 5 re-captures them.

**Passed, with the evidence:**

| Item | Evidence |
|---|---|
| G1 GPL-compatible | MIT (Expat) is on the GNU compatible list; `LICENSE` ships in the zip. |
| G4 readable, source available | No build step or minified code; the zip carries the source. |
| G5 no trialware | No code path is locked. The 20-document free quota is the service's, which G6 permits. |
| G6 SaaS documented | `== External services ==` names the service, the data sent and when, plus Terms and Privacy links. |
| G7 no calls without consent | `wp_remote_request` is only reached from `InvoiceGenerator::generate`, which runs on the configured status or a manual action, after an API key is entered. |
| G8, G13 no remote code, no bundled core libraries | No enqueued scripts or styles at all; the only bundle is our own autoloader. TLS verification is left on and `redirection` is 0. |
| G10 no front-end credits | No front-end hook: every hook is admin-side, the order status change, or `admin_post`. |
| G11 no dashboard hijack | The generation notice is one-shot, per user and dismissible. The missing-WooCommerce notice goes away once WooCommerce is active, and `Requires Plugins` normally prevents that state. |
| G12 readme not spam | 5 tags, the limit. |
| G17 name | "beliq e-invoicing" starts with our own brand. The FAQ says ownership is judged by the submitting account's email, so it must be an `@beliq.eu` address that does not auto-reply or feed a helpdesk. |
| FAQ: under 10 MB, installable, no dev files | 38,519 bytes, `beliq-e-invoicing/` top-level, `DIST` only. |
| FAQ: tested with `WP_DEBUG` | 2026-09-19 on WordPress 7.1.1 + WooCommerce 11.1.1: plugin load, settings screen render, metabox render, and a status change that ran generation into a dead URL. Zero notices, warnings or deprecations. A planted `E_USER_NOTICE` reached `debug.log`, so the silence is real. |
| Plugin Check, runtime checks included | `wp plugin check --include-experimental --require=.../plugin-check/cli.php` with WooCommerce active: no errors. `list-checks` confirms the runtime checks load with that flag. |

## Parked / out of scope

- **`plugin-check/run.sh` runs static checks only.** Plugin Check's own docs say
  WP-CLI needs `--require=<plugin-check>/cli.php` for its runtime checks, and
  those activate the plugin, which needs WooCommerce present. 3.7 ran them by
  hand once. Wiring it in means the gate installs WooCommerce first. Small; it
  blocks nothing today because the plugin enqueues no assets.
- **No harness turns on `WP_DEBUG`.** `plugin-check/` and `smoke/` both run with
  it off, so a notice introduced later would go unseen. Small: `wp config set
  WP_DEBUG true --raw` plus a `debug.log` assertion in `smoke/run.sh`.

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
