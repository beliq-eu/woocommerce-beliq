# WordPress.org submission gate

Runs the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) plugin
against the plugin's **distribution** on the **current** WordPress release. An error in
the `plugin_repo` category blocks the wp.org submission before a human reviewer sees the
plugin, so this is the gate to clear before uploading a ZIP.

```bash
./run.sh                   # Plugin Check
./run.sh --with-surfaces   # also install WooCommerce, activate, assert the runtime surfaces
./run.sh down              # tear down and wipe the volumes
```

Nothing here needs a beliq API key or a running beliq API. That path is `smoke/`.

## Two things it does that a naive run does not

**It checks the distribution, not the repo.** Plugin Check reads every file in the
plugin directory. `vendor/`, `tests/`, `smoke/` and this harness are not submitted, so
`run.sh` stages only the shipped paths (the `DIST` array). Keep that array in step with
any new shipped file.

**It stages under the wp.org slug, `beliq-e-invoicing`.** Plugin Check derives the
expected text domain and the trademark verdict from the plugin **directory name**, which
on wp.org is the slug. Under the repo's own directory name the text-domain check compares
the domain against itself and passes, and the trademark check has nothing to object to.
Both would be checking the wrong thing.

The slug comes from the plugin's display name ("beliq e-invoicing"), which is what wp.org
derives it from at submission. It is deliberately not `woocommerce-beliq`: a slug whose
first term is `woocommerce` is a Guideline 17 trademark rejection.

## What `--with-surfaces` adds

`surfaces.php` asserts the plugin loads and hooks itself in on the WordPress and
WooCommerce the harness just installed: the integration registers, the order-status
trigger and both admin-post endpoints are bound to **this plugin's** callbacks, HPOS
compatibility is declared, and the text domain matches the slug. That is what the
readme's `Tested up to` line stands on; generating an actual invoice is `smoke/`.

The hook assertions look for a callback bound to the plugin's own class rather than
calling `has_action()`, because WooCommerce hooks `woocommerce_order_status_changed`
itself and a bare `has_action()` stays green with the plugin deactivated.
