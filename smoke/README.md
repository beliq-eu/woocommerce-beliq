# Pass 3 live smoke (Dockerized WordPress + WooCommerce)

Boots a real WordPress + WooCommerce store with the plugin installed and drives a
B2B order through the full runtime path against a local beliq api + engine:

```
woocommerce_order_status_changed -> OrderStatusTrigger -> WcOrderData
  -> WooOrderAdapter -> Core\InvoiceMapper -> Core\BeliqClient -> /v1/generate
  -> DocumentStore
```

For each format case it asserts the chain stored a green EN 16931 document, that
the order meta round-trips through HPOS storage, and that the download resolves
and is capability-gated. It also covers the business-only skip and auto-vs-manual
idempotency. Cases: German XRechnung (xml), French Peppol BIS (xml), German
ZUGFeRD (hybrid pdf).

## Prerequisites: a local beliq api + engine

The store reaches the host api at `host.docker.internal:3000`; the api reaches the
engine at `localhost:8000`.

1. **Postgres + Redis** (the `beliq-infra/local` dev stack) and current migrations:

   ```bash
   cd ../../../beliq-infra/local && docker compose -f docker-compose.dev.yml up -d
   cd ../../beliq-db && yarn db:migrate
   ```

2. **Engine** on :8000 (build once from `beliq-engine`, run unauthenticated):

   ```bash
   cd ../../../beliq-engine && docker build -t beliq-engine:smoke .
   docker run -d --name beliq-engine-smoke -p 8000:8000 -e ALLOW_UNAUTHENTICATED=true beliq-engine:smoke
   ```

3. **API** on :3000, pointed at the engine and the dev DB:

   ```bash
   cd ../../../beliq-api && ENGINE_URL=http://localhost:8000 yarn dev
   ```

4. **An API key valid for the local api.** The api keeps `ALLOW_UNAUTHENTICATED=false`,
   so mint a key against the dev DB (idempotent demo org) and export it:

   ```bash
   export BELIQ_API_KEY=sk_...    # a key whose hash (with the api's API_TOKEN_SECRET) is in api_keys
   ```

## Run

```bash
BELIQ_API_KEY=sk_... ./run.sh
```

It leaves the store running at http://localhost:8091 (admin / admin) so you can
take WordPress.org screenshots. Tear everything down with:

```bash
./run.sh down
```

## Notes

- The plugin is bind-mounted read-only from the repo root; the store writes only
  to `wp-content/uploads/beliq-invoices`.
- Green is asserted by re-validating the stored bytes via `/v1/validate`, not by
  trusting the generate 200 (generate already validates internally and 422s on a
  non-green document, so this is defense in depth).
- This box's host PHP lacks the dom/mbstring extensions PHPUnit needs; the WP
  container has a complete PHP, which is why the full-path smoke runs there.
