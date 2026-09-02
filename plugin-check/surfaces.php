<?php
/**
 * Asserts the plugin's runtime surfaces register on the WordPress and
 * WooCommerce the harness just installed. This is what backs the readme's
 * "Tested up to" line: not that an invoice was generated (that is smoke/), but
 * that the plugin loads and hooks itself in with no error on that core version.
 *
 * Every check guards its own preconditions, so a plugin that failed to load
 * reports every line FAIL instead of fataling on the first one.
 */

$failed = 0;
$check = static function (string $label, callable $probe) use (&$failed): void {
    try {
        $ok = (bool) $probe();
    } catch (\Throwable $e) {
        $ok = false;
        $label .= ' (' . $e->getMessage() . ')';
    }
    WP_CLI::log(($ok ? '  ok    ' : '  FAIL  ') . $label);
    if (!$ok) {
        ++$failed;
    }
};

$mainFile = 'beliq-e-invoicing/woocommerce-beliq.php';

/**
 * True when $hook carries a callback bound to $class. WooCommerce hooks some of
 * these itself, so a bare has_action() would stay green with the plugin off.
 */
$hookedBy = static function (string $hook, string $class): bool {
    global $wp_filter;
    if (!isset($wp_filter[$hook])) {
        return false;
    }
    foreach ($wp_filter[$hook] as $callbacks) {
        foreach ($callbacks as $callback) {
            $fn = $callback['function'] ?? null;
            if (is_array($fn) && is_object($fn[0]) && $fn[0] instanceof $class) {
                return true;
            }
        }
    }

    return false;
};

$check('WpHttpClient autoloads', static fn () => class_exists(\Beliq\WooCommerce\Http\WpHttpClient::class));
$check('WpHttpClient implements the HttpClient seam', static fn () => class_exists(\Beliq\WooCommerce\Http\WpHttpClient::class)
    && in_array(\Beliq\Core\Service\HttpClient::class, class_implements(\Beliq\WooCommerce\Http\WpHttpClient::class), true));
$check('the cURL transport is not shipped', static fn () => !class_exists(\Beliq\Core\Service\CurlHttpClient::class));
$check('the WooCommerce integration is registered', static fn () => in_array(
    \Beliq\WooCommerce\Integration\InvoiceIntegration::class,
    apply_filters('woocommerce_integrations', []),
    true,
));
$check('the order-status trigger is hooked', static fn () => $hookedBy(
    'woocommerce_order_status_changed',
    \Beliq\WooCommerce\Order\OrderStatusTrigger::class,
));
$check('the download endpoint is hooked', static fn () => has_action('admin_post_beliq_download_invoice') !== false);
$check('the generate endpoint is hooked', static fn () => has_action('admin_post_beliq_generate_invoice') !== false);
$check('the order action is filtered in', static fn () => $hookedBy(
    'woocommerce_order_actions',
    \Beliq\WooCommerce\Admin\OrderActions::class,
));
$check('HPOS compatibility is declared', static fn () => in_array(
    $mainFile,
    \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature('custom_order_tables')['compatible'] ?? [],
    true,
));
$check('the text domain matches the wp.org slug', static fn () => get_file_data(
    WP_PLUGIN_DIR . '/' . $mainFile,
    ['TextDomain' => 'Text Domain'],
)['TextDomain'] === 'beliq-e-invoicing');

WP_CLI::log('');
if ($failed > 0) {
    WP_CLI::error($failed . ' surface check(s) failed');
}
WP_CLI::success('all surfaces present on WordPress ' . get_bloginfo('version') . ' + WooCommerce ' . WC()->version);
