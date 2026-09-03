#!/usr/bin/env bash
# The wp.org submission gate: runs the official Plugin Check plugin against the
# distribution, on the current WordPress. An error in the plugin_repo category
# blocks submission before a human reviewer sees the plugin at all.
#
# Usage:
#   ./run.sh                   # every check, including the calendar-driven ones
#   ./run.sh --ignore-calendar # what the PR gate runs (see CALENDAR_CODES)
#   ./run.sh --with-surfaces   # also install WooCommerce, activate, assert the surfaces
#   ./run.sh down              # tear the stack down and wipe volumes
#
# Exits non-zero on any Plugin Check ERROR, or on a failed surface assertion.
set -euo pipefail

cd "$(dirname "$0")"

# The wp.org slug. Plugin Check derives the expected text domain and the
# trademark verdict from the plugin directory name, so checking under any other
# name checks the wrong thing.
SLUG="beliq-e-invoicing"

# What goes into the submitted ZIP and the SVN trunk. Everything else in the
# repo is development-only. Keep this list in step with any new shipped path.
DIST=(woocommerce-beliq.php src languages readme.txt LICENSE)

# The codes whose input is the calendar rather than this repo:
# outdated_tested_upto_header compares readme.txt against whatever wordpress.org
# calls current today, so it starts failing on a WordPress release with nothing
# changed here. The PR gate drops it so it cannot redden unrelated work; the
# weekly wporg-currency workflow runs without --ignore-calendar, which is what
# makes the staleness surface at all.
CALENDAR_CODES="outdated_tested_upto_header"

TEARDOWN=0
WITH_SURFACES=0
IGNORE_CODES=""

for arg in "$@"; do
    case "$arg" in
        down) TEARDOWN=1 ;;
        --with-surfaces) WITH_SURFACES=1 ;;
        --ignore-calendar) IGNORE_CODES="$CALENDAR_CODES" ;;
        *) echo "unknown argument: $arg" >&2; exit 2 ;;
    esac
done

DC="docker compose"
WP() { $DC exec -T wpcli wp --path=/var/www/html "$@"; }

if [[ "$TEARDOWN" == 1 ]]; then
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

CHECK_ARGS=(--include-experimental)
if [[ -n "$IGNORE_CODES" ]]; then
    CHECK_ARGS+=("--ignore-codes=$IGNORE_CODES")
    echo "== Plugin Check (ignoring: $IGNORE_CODES) =="
else
    echo "== Plugin Check =="
fi

WP plugin check "$SLUG" "${CHECK_ARGS[@]}"

# `wp plugin check` exits 0 whatever it finds, in every output format, so the
# verdict has to be read out of a second machine-readable run. strict-json
# prints a JSON array when there are findings and a plain success line when
# there are none, which means "did not parse as JSON" must fail rather than read
# as clean.
WP plugin check "$SLUG" "${CHECK_ARGS[@]}" --format=strict-json > dist/findings.json

echo "== Verdict =="
python3 - dist/findings.json <<'PY'
import json
import pathlib
import sys

raw = pathlib.Path(sys.argv[1]).read_text().strip()

if raw.startswith("["):
    rows = json.loads(raw)
    errors = [r for r in rows if r.get("type") == "ERROR"]
    warnings = [r for r in rows if r.get("type") == "WARNING"]
    print(f"  {len(errors)} error(s), {len(warnings)} warning(s)")
    for r in errors:
        print(f"  ERROR {r.get('code')}: {r.get('message', '')[:140]}")
    sys.exit(1 if errors else 0)

if "Checks complete. No errors found." in raw:
    print("  0 errors, 0 warnings")
    sys.exit(0)

print("  unrecognised plugin-check output, refusing to read it as clean:", file=sys.stderr)
print(raw[:400], file=sys.stderr)
sys.exit(1)
PY

if [[ "$WITH_SURFACES" == 1 ]]; then
    echo "== Installing WooCommerce =="
    WP plugin is-active woocommerce 2>/dev/null || WP plugin install woocommerce --activate
    echo "  WooCommerce $(WP plugin get woocommerce --field=version)"
    WP plugin is-active "$SLUG" 2>/dev/null || WP plugin activate "$SLUG"
    echo "== Runtime surfaces =="
    WP eval-file /harness/surfaces.php
fi
