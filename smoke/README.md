# Live smoke (Dockerized WordPress + WooCommerce)

Boots a real WordPress + WooCommerce store with the plugin installed and drives a
B2B order through the full runtime path against a beliq API:

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

## Prerequisites: a beliq API and a key for it

`BELIQ_BASE_URL` names the API the store calls, and `BELIQ_API_KEY` is a key that API
accepts. Without `BELIQ_BASE_URL` the store calls `http://host.docker.internal:3000`, which
is port 3000 on the Docker host. That default is for a beliq API running on the same
machine. To run against the public API:

```bash
export BELIQ_BASE_URL=https://api.beliq.eu
export BELIQ_API_KEY=sk_...
```

Each case calls `/v1/generate` and `/v1/validate` with that key.

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
- The full-path smoke runs inside the WordPress container, so it does not depend on the
  PHP extensions installed on the host.
