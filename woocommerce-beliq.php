<?php
/**
 * Plugin Name:          beliq e-invoicing
 * Plugin URI:           https://beliq.eu
 * Description:          Generate compliant EN 16931 e-invoices (XRechnung, ZUGFeRD, Factur-X, Peppol BIS) from WooCommerce orders through the beliq API. beliq generates and validates the document; sending, archiving, and filing stay with you.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               beliq
 * Author URI:           https://beliq.eu
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          woocommerce-beliq
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      9.4
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// PSR-4 autoloader for the bundled classes. The plugin ships self-contained (no
// runtime Composer dependency), so it resolves Beliq\Core\ to src/Core/ and the
// remaining Beliq\WooCommerce\ classes to src/.
spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Beliq\\Core\\' => __DIR__ . '/src/Core/',
        'Beliq\\WooCommerce\\' => __DIR__ . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

// Declare High-Performance Order Storage compatibility: this plugin reads and
// writes order data only through the WC_Order CRUD API, so it is HPOS-safe.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('beliq e-invoicing requires WooCommerce to be installed and active.', 'woocommerce-beliq')
                . '</p></div>';
        });

        return;
    }

    \Beliq\WooCommerce\Plugin::instance()->boot();
});
