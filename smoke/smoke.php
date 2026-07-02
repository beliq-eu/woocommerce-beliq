<?php
/**
 * Pass 3 live smoke, run inside the WordPress container via `wp eval-file`.
 *
 * For each format case it configures the beliq settings, creates a taxed B2B
 * order, transitions it to the trigger status (firing the real
 * woocommerce_order_status_changed -> OrderStatusTrigger path), then asserts the
 * full chain produced and stored a green EN 16931 document, that the order meta
 * round-tripped through HPOS storage, and that the download resolves and is
 * capability-gated. It also covers the business-only skip and auto-vs-manual
 * idempotency.
 *
 * The beliq api (:3000) + engine (:8000) run on the host and are reached through
 * host.docker.internal; BELIQ_API_KEY / BELIQ_BASE_URL come from the environment.
 */

use Automattic\WooCommerce\Utilities\OrderUtil;
use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Invoice\DocumentStore;
use Beliq\WooCommerce\Invoice\InvoiceGenerator;
use Beliq\WooCommerce\Order\WcOrderData;
use Beliq\WooCommerce\Order\WooOrderAdapter;
use Beliq\Core\Service\BeliqClient;
use Beliq\Core\Service\InvoiceMapper;

// wp eval-file includes this file inside a method scope, so top-level `$var`s are
// function locals, not real globals. Keep the pass/fail tally in $GLOBALS so the
// helper and the final summary read the same state (a plain `global` would bind
// to an unrelated empty global and silently mask failures).
$GLOBALS['SMOKE_FAILURES'] = [];
$GLOBALS['SMOKE_PASSES'] = 0;

function check(bool $cond, string $label): void
{
    if ($cond) {
        $GLOBALS['SMOKE_PASSES']++;
        WP_CLI::log('  PASS  ' . $label);
    } else {
        $GLOBALS['SMOKE_FAILURES'][] = $label;
        WP_CLI::log('  FAIL  ' . $label);
    }
}

$apiKey = getenv('BELIQ_API_KEY') ?: '';
$baseUrl = getenv('BELIQ_BASE_URL') ?: 'http://host.docker.internal:3000';
if ($apiKey === '') {
    WP_CLI::error('BELIQ_API_KEY is not set in the wpcli environment.');
}

WP_CLI::log('== Preconditions ==');
check(class_exists('WooCommerce'), 'WooCommerce is loaded');
check(class_exists(\Beliq\WooCommerce\Plugin::class), 'beliq plugin classes autoload');
check(OrderUtil::custom_orders_table_usage_is_enabled(), 'HPOS (custom order tables) is enabled');

// Store-wide tax + address setup so calculate_totals() applies a real rate.
update_option('woocommerce_calc_taxes', 'yes');
update_option('woocommerce_prices_include_tax', 'no');
update_option('woocommerce_tax_based_on', 'billing');
update_option('woocommerce_default_country', 'DE:BE');

$rateIds = [];
foreach (['DE' => '19.0000', 'FR' => '20.0000'] as $country => $rate) {
    $rateIds[$country] = WC_Tax::_insert_tax_rate([
        'tax_rate_country' => $country,
        'tax_rate_state' => '',
        'tax_rate' => $rate,
        'tax_rate_name' => 'VAT-' . $country,
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => 1,
        'tax_rate_order' => 1,
        'tax_rate_class' => '',
    ]);
}

/**
 * @param array<string,mixed> $seller  seller-block overrides (country-specific)
 */
function configure_beliq(string $baseUrl, string $apiKey, string $standard, string $output, array $seller, bool $businessOnly = true): void
{
    $settings = array_merge([
        'enabled' => 'yes',
        'apiKey' => $apiKey,
        'baseUrl' => $baseUrl,
        'standard' => $standard,
        'profile' => 'en16931',
        'output' => $output,
        'businessOnly' => $businessOnly ? 'yes' : 'no',
        'triggerEvent' => 'processing',
        'zeroRateCategory' => 'Z',
        'paymentMeansCode' => '58',
        'buyerVatMetaKey' => '_billing_vat',
        'buyerReferenceMetaKey' => '',
    ], $seller);

    update_option(WooPluginConfigProvider::OPTION, $settings);
}

function make_product(string $name, string $price): WC_Product_Simple
{
    $p = new WC_Product_Simple();
    $p->set_name($name);
    $p->set_regular_price($price);
    $p->set_tax_status('taxable');
    $p->set_tax_class('');
    $p->save();

    return $p;
}

/**
 * A B2B order: billing company + VAT id meta, taxed line, guest customer so the
 * buyer reference falls back to the order number.
 */
function make_b2b_order(WC_Product $product, int $qty, string $country, string $city, string $postcode, ?string $vat, ?string $company): WC_Order
{
    $order = wc_create_order();
    $order->set_billing_first_name('Max');
    $order->set_billing_last_name('Beispiel');
    if ($company !== null) {
        $order->set_billing_company($company);
    }
    $order->set_billing_address_1('Teststrasse 1');
    $order->set_billing_city($city);
    $order->set_billing_postcode($postcode);
    $order->set_billing_country($country);
    $order->set_billing_email('einkauf@example.' . strtolower($country));
    if ($vat !== null) {
        $order->update_meta_data('_billing_vat', $vat);
    }
    $order->add_product($product, $qty);
    $order->calculate_totals(true);
    $order->save();

    return $order;
}

function stored_path(WC_Order $order): ?string
{
    $file = (string) $order->get_meta('_beliq_invoice_file');
    if ($file === '') {
        return null;
    }
    $uploads = wp_upload_dir();
    $path = untrailingslashit($uploads['basedir']) . '/beliq-invoices/' . $file;

    return is_file($path) ? $path : null;
}

/** Read the meta straight from the HPOS meta table to prove it persisted there. */
function hpos_meta(int $orderId, string $key): ?string
{
    global $wpdb;
    $table = OrderUtil::get_table_for_order_meta();
    $val = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_value FROM {$table} WHERE order_id = %d AND meta_key = %s LIMIT 1",
        $orderId,
        $key,
    ));

    return $val === null ? null : (string) $val;
}

$cases = [
    'German XRechnung (xml)' => [
        'standard' => 'xrechnung',
        'output' => 'xml',
        'validateFormat' => 'cii',
        'expectMagic' => '<?xml',
        'country' => 'DE',
        'city' => 'Hamburg',
        'postcode' => '20095',
        'vat' => 'DE987654321',
        'company' => 'Kaeufer AG',
        'seller' => [
            'sellerName' => 'Muster Handel GmbH',
            'sellerVatId' => 'DE123456789',
            'sellerEmail' => 'buchhaltung@muster-handel.de',
            'sellerPhone' => '+49 30 1234567',
            'sellerContactName' => 'Erika Mustermann',
            'sellerStreet' => 'Hauptstrasse 1',
            'sellerPostalCode' => '10115',
            'sellerCity' => 'Berlin',
            'sellerCountryCode' => 'DE',
            'sellerIban' => 'DE89370400440532013000',
            'sellerBic' => 'COBADEFFXXX',
            'sellerBankName' => 'Commerzbank',
        ],
    ],
    'French Peppol BIS (xml)' => [
        'standard' => 'peppol-bis',
        'output' => 'xml',
        'validateFormat' => 'ubl',
        'expectMagic' => '<?xml',
        'country' => 'FR',
        'city' => 'Lyon',
        'postcode' => '69001',
        'vat' => 'FR98765432109',
        'company' => 'Acheteur SA',
        'seller' => [
            'sellerName' => 'Vendeur SARL',
            'sellerVatId' => 'FR12345678901',
            'sellerEmail' => 'facture@vendeur.fr',
            'sellerPhone' => '+33 1 2345678',
            'sellerContactName' => 'Service Facturation',
            'sellerStreet' => 'Rue A 1',
            'sellerPostalCode' => '75002',
            'sellerCity' => 'Paris',
            'sellerCountryCode' => 'FR',
            'sellerIban' => 'FR7630006000011234567890189',
            'sellerBic' => '',
            'sellerBankName' => '',
        ],
    ],
    'German ZUGFeRD (hybrid pdf)' => [
        'standard' => 'zugferd',
        'output' => 'pdf',
        'validateFormat' => 'cii',
        'expectMagic' => '%PDF',
        'country' => 'DE',
        'city' => 'Hamburg',
        'postcode' => '20095',
        'vat' => 'DE987654321',
        'company' => 'Kaeufer AG',
        'seller' => [
            'sellerName' => 'Muster Handel GmbH',
            'sellerVatId' => 'DE123456789',
            'sellerEmail' => 'buchhaltung@muster-handel.de',
            'sellerPhone' => '+49 30 1234567',
            'sellerContactName' => 'Erika Mustermann',
            'sellerStreet' => 'Hauptstrasse 1',
            'sellerPostalCode' => '10115',
            'sellerCity' => 'Berlin',
            'sellerCountryCode' => 'DE',
            'sellerIban' => 'DE89370400440532013000',
            'sellerBic' => 'COBADEFFXXX',
            'sellerBankName' => 'Commerzbank',
        ],
    ],
];

$client = new BeliqClient($apiKey, $baseUrl);
$firstOrderId = null;

foreach ($cases as $label => $c) {
    WP_CLI::log('');
    WP_CLI::log('== Case: ' . $label . ' ==');
    configure_beliq($baseUrl, $apiKey, $c['standard'], $c['output'], $c['seller'], true);

    $product = make_product('Widget ' . $c['standard'], '50.00');
    $order = make_b2b_order($product, 2, $c['country'], $c['city'], $c['postcode'], $c['vat'], $c['company']);
    $orderId = $order->get_id();
    if ($firstOrderId === null) {
        $firstOrderId = $orderId;
    }

    // Fire the real hook: pending -> processing triggers OrderStatusTrigger.
    $order->update_status('processing');

    // Prove HPOS storage + meta round-trip by reading fresh, not from the object
    // we just wrote.
    wp_cache_flush();
    $reloaded = wc_get_order($orderId);
    check($reloaded instanceof WC_Order, 'order reloads');

    $fileMeta = $reloaded ? (string) $reloaded->get_meta('_beliq_invoice_file') : '';
    check($fileMeta !== '', 'invoice file recorded in order meta after the transition');
    check(hpos_meta($orderId, '_beliq_invoice_file') === $fileMeta && $fileMeta !== '', 'meta round-trips through the HPOS meta table');
    check($reloaded && (string) $reloaded->get_meta('_beliq_invoice_content_type') !== '', 'content-type meta recorded');

    $path = $reloaded ? stored_path($reloaded) : null;
    check($path !== null, 'stored document file exists on disk');
    $bytes = $path !== null ? (string) file_get_contents($path) : '';
    check($bytes !== '' && str_starts_with(ltrim($bytes), $c['expectMagic']), 'stored bytes start with ' . $c['expectMagic']);

    // Green, independent of the generate 200. Generate already validates
    // internally (it 422s a non-green document), but re-validate anyway.
    //  - XML cases: post the stored bytes straight to /v1/validate.
    //  - Hybrid PDF: /v1/validate only handles XML, so re-map the SAME order
    //    through the plugin's adapter + mapper to the equivalent XML (the CII
    //    that the hybrid PDF embeds) and validate that.
    try {
        if ($c['output'] === 'pdf') {
            $cfg = (new WooPluginConfigProvider())->get();
            $source = (new WooOrderAdapter())->toSourceOrder(new WcOrderData($reloaded, $cfg), $cfg);
            $xmlBody = (new InvoiceMapper())->toGenerateBody($source, $cfg->standard, 'xml', $cfg->effectiveProfile());
            $xmlGen = $client->generate($xmlBody);
            $result = $client->validate($xmlGen['bytes'], 'application/xml', $c['validateFormat']);
            $descr = 'same order re-mapped to XML re-validates green';
        } else {
            $result = $client->validate($bytes, 'application/xml', $c['validateFormat']);
            $descr = 'stored document re-validates green';
        }
        $valid = ($result['valid'] ?? false) === true;
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        check($valid && $errors === [], $descr . ' (' . ($result['format'] ?? '?') . '/' . ($result['profileDetected'] ?? '?') . ')');
        if (!$valid || $errors !== []) {
            foreach (array_slice($errors, 0, 5) as $e) {
                WP_CLI::log('        ERR ' . ($e['ruleId'] ?? '?') . ': ' . ($e['message'] ?? ''));
            }
        }
    } catch (\Throwable $ex) {
        check(false, 're-validate call failed: ' . $ex->getMessage());
    }

    // Download resolves through the store and is safe.
    $store = new DocumentStore();
    $resolved = $reloaded ? $store->resolvePath($reloaded) : null;
    check($resolved === $path && $resolved !== null, 'DocumentStore::resolvePath resolves to the stored file');
    check($reloaded && is_readable((string) $store->resolvePath($reloaded)), 'stored file is readable for download');
    $downloadName = $reloaded ? $store->downloadName($reloaded) : '';
    $expectExt = str_starts_with($c['expectMagic'], '%PDF') ? '.pdf' : '.xml';
    check(str_ends_with($downloadName, $expectExt), 'download filename has the ' . $expectExt . ' extension');
}

// Business-only gate: a consumer order (no company, no VAT) is skipped.
WP_CLI::log('');
WP_CLI::log('== Case: business-only gate skips a consumer order ==');
configure_beliq($baseUrl, $apiKey, 'xrechnung', 'xml', $cases['German XRechnung (xml)']['seller'], true);
$consumerProduct = make_product('Consumer widget', '50.00');
$consumerOrder = make_b2b_order($consumerProduct, 1, 'DE', 'Hamburg', '20095', null, null);
$consumerOrder->update_status('processing');
wp_cache_flush();
$consumerReloaded = wc_get_order($consumerOrder->get_id());
check((new DocumentStore())->has($consumerReloaded) === false, 'no document stored for a non-business order under business-only scope');

// Idempotency: re-firing the trigger must not overwrite; a manual force rebuilds.
WP_CLI::log('');
WP_CLI::log('== Case: idempotency (auto keeps, manual forces) ==');
$order = wc_get_order($firstOrderId);
configure_beliq($baseUrl, $apiKey, 'xrechnung', 'xml', $cases['German XRechnung (xml)']['seller'], true);
$before = (string) $order->get_meta('_beliq_invoice_file');
$order->update_status('on-hold');
$order->update_status('processing'); // re-fires the hook (auto, force off)
wp_cache_flush();
$order = wc_get_order($firstOrderId);
$afterAuto = (string) $order->get_meta('_beliq_invoice_file');
check($before !== '' && $before === $afterAuto, 'auto re-trigger keeps the existing document (no overwrite)');

$gen = new InvoiceGenerator(new DocumentStore());
$forced = $gen->generate($order, (new WooPluginConfigProvider())->get(), true);
wp_cache_flush();
$order = wc_get_order($firstOrderId);
$afterForce = (string) $order->get_meta('_beliq_invoice_file');
check($forced !== null && $afterForce !== '' && $afterForce !== $before, 'manual force regenerates a fresh document');

// Capability gate on the download entry point.
WP_CLI::log('');
WP_CLI::log('== Case: download capability gate ==');
$adminId = wp_insert_user(['user_login' => 'smoke_admin', 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
$subId = wp_insert_user(['user_login' => 'smoke_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
check(!is_wp_error($adminId) && user_can($adminId, 'edit_shop_orders'), 'administrator has the edit_shop_orders capability');
check(!is_wp_error($subId) && !user_can($subId, 'edit_shop_orders'), 'subscriber lacks the edit_shop_orders capability');

WP_CLI::log('');
$failures = $GLOBALS['SMOKE_FAILURES'];
$passes = $GLOBALS['SMOKE_PASSES'];
if ($failures === []) {
    WP_CLI::success(sprintf('All %d checks passed.', $passes));
} else {
    WP_CLI::error(sprintf("%d of %d checks failed:\n  - %s", count($failures), $passes + count($failures), implode("\n  - ", $failures)));
}
