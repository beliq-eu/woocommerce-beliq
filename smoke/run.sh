#!/usr/bin/env bash
# Pass 3 live smoke driver: brings up WordPress + WooCommerce + the plugin and
# runs smoke.php against a local beliq api/engine.
#
# Prerequisites (see README.md): the beliq api on :3000 and engine on :8000 are
# running on the host, and BELIQ_API_KEY is exported (a key valid for the local
# api). The store reaches the host api through host.docker.internal.
#
# Usage:
#   BELIQ_API_KEY=... ./run.sh          # up, install, run the smoke (leaves the stack up)
#   ./run.sh down                       # tear the stack down and wipe volumes
set -euo pipefail

cd "$(dirname "$0")"

SITE_URL="http://localhost:8091"
DC="docker compose"
WP() { $DC exec -T wpcli wp --path=/var/www/html "$@"; }

if [[ "${1:-}" == "down" ]]; then
    $DC down -v
    exit 0
fi

: "${BELIQ_API_KEY:?export BELIQ_API_KEY (a key valid for the local api on :3000)}"
export BELIQ_API_KEY
export BELIQ_BASE_URL="${BELIQ_BASE_URL:-http://host.docker.internal:3000}"

echo "== Preflight: host api reachable =="
if ! curl -fsS -m 5 http://localhost:3000/health/live >/dev/null; then
    echo "FAIL: beliq api not reachable at http://localhost:3000/health/live" >&2
    echo "      Start the engine (:8000) and api (:3000) first (see README.md)." >&2
    exit 1
fi
echo "  ok"

echo "== Bringing up the stack =="
$DC up -d --wait

echo "== Waiting for WordPress files to populate =="
for _ in $(seq 1 40); do
    if $DC exec -T wpcli test -f /var/www/html/wp-load.php 2>/dev/null; then break; fi
    sleep 2
done

echo "== Installing WordPress core =="
if ! WP core is-installed 2>/dev/null; then
    WP core install \
        --url="$SITE_URL" \
        --title="beliq WooCommerce smoke" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=smoke@beliq.eu \
        --skip-email
fi

echo "== Installing + activating WooCommerce =="
WP plugin is-active woocommerce 2>/dev/null || WP plugin install woocommerce --activate

echo "== Enabling HPOS (custom order tables) =="
WP option update woocommerce_feature_custom_order_tables_enabled yes >/dev/null
WP option update woocommerce_custom_orders_table_enabled yes >/dev/null

echo "== Activating the beliq plugin =="
WP plugin activate woocommerce-beliq

echo "== Running the smoke =="
set +e
WP eval-file /smoke/smoke.php
code=$?
set -e

echo ""
if [[ $code -eq 0 ]]; then
    echo "SMOKE PASSED. Store left running at $SITE_URL (admin/admin). Tear down with: ./run.sh down"
else
    echo "SMOKE FAILED (exit $code). Store left up for inspection at $SITE_URL. Tear down with: ./run.sh down" >&2
fi
exit $code
