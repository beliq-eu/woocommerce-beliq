#!/usr/bin/env bash
# The wp.org submission gate: runs the official Plugin Check plugin against the
# distribution, on the current WordPress. An error in the plugin_repo category
# blocks submission before a human reviewer sees the plugin at all.
#
# Usage:
#   ./run.sh                # Plugin Check against the distribution
#   ./run.sh --with-surfaces  # also install WooCommerce, activate, assert the surfaces
#   ./run.sh down           # tear the stack down and wipe volumes
set -euo pipefail

cd "$(dirname "$0")"

# The wp.org slug. Plugin Check derives the expected text domain and the
# trademark verdict from the plugin directory name, so checking under any other
# name checks the wrong thing.
SLUG="beliq-e-invoicing"

# What goes into the submitted ZIP and the SVN trunk. Everything else in the
# repo is development-only. Keep this list in step with any new shipped path.
DIST=(woocommerce-beliq.php src languages readme.txt LICENSE)

DC="docker compose"
WP() { $DC exec -T wpcli wp --path=/var/www/html "$@"; }

if [[ "${1:-}" == "down" ]]; then
    $DC down -v
    rm -rf dist
    exit 0
fi

echo "== Staging the distribution =="
rm -rf "dist/$SLUG"
mkdir -p "dist/$SLUG"
for path in "${DIST[@]}"; do
    cp -r "../$path" "dist/$SLUG/"
done
echo "  $(find "dist/$SLUG" -type f | wc -l) files"

echo "== Bringing up WordPress =="
$DC up -d --wait

for _ in $(seq 1 40); do
    if $DC exec -T wpcli test -f /var/www/html/wp-load.php 2>/dev/null; then break; fi
    sleep 2
done

WP core is-installed 2>/dev/null || WP core install \
    --url=http://localhost \
    --title="beliq plugin check" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=plugin-check@beliq.eu \
    --skip-email

# The image lags the current release, and outdated_tested_upto_header compares
# the readme against whatever wordpress.org calls current right now.
echo "== Updating core to the current release =="
WP core update --force
WP core update-db
echo "  WordPress $(WP core version)"

WP plugin is-active plugin-check 2>/dev/null || WP plugin install plugin-check --activate

echo "== Installing the distribution as $SLUG =="
$DC exec -T -u 0 wpcli sh -c "rm -rf /var/www/html/wp-content/plugins/$SLUG \
    && cp -r /harness/dist/$SLUG /var/www/html/wp-content/plugins/$SLUG \
    && chown -R 33:33 /var/www/html/wp-content/plugins/$SLUG"

echo "== Plugin Check =="
WP plugin check "$SLUG" --include-experimental

if [[ "${1:-}" == "--with-surfaces" ]]; then
    echo "== Installing WooCommerce =="
    WP plugin is-active woocommerce 2>/dev/null || WP plugin install woocommerce --activate
    echo "  WooCommerce $(WP plugin get woocommerce --field=version)"
    WP plugin is-active "$SLUG" 2>/dev/null || WP plugin activate "$SLUG"
    echo "== Runtime surfaces =="
    WP eval-file /harness/surfaces.php
fi
