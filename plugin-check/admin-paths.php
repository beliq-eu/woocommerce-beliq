<?php
/**
 * Drives the plugin's admin paths through real HTTP requests, logged in as the
 * admin, so that WP_DEBUG sees the code a merchant runs: the settings screen,
 * an automatic generation that fails at connect, the order screen with the
 * beliq box showing that failure, and a manual "Generate now" with its notice.
 * run.sh then fails on any line in wp-content/debug.log.
 *
 * Nothing here needs a beliq API: the configured base URL refuses connections,
 * which is itself a path worth covering (the failure is recorded on the order).
 * Generating a real invoice is smoke/.
 */

use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Retry\OrderNotesRecorder;

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

// Port 9 (discard) has no listener in the wordpress container, so the
// connection is refused at once instead of waiting for a timeout.
$deadApi = 'http://127.0.0.1:9';

update_option(WooPluginConfigProvider::OPTION, [
    'enabled' => 'yes',
    'apiKey' => 'blq_admin_paths_not_a_key',
    'baseUrl' => $deadApi,
    'standard' => 'xrechnung',
    'profile' => 'en16931',
    'output' => 'xml',
    'businessOnly' => 'yes',
    'triggerEvent' => 'processing',
    'zeroRateCategory' => 'Z',
    'paymentMeansCode' => '58',
    'buyerVatMetaKey' => '_billing_vat',
    'buyerReferenceMetaKey' => '',
]);

// WooCommerce mails the store on a new processing order, and the stack has no
// mail server; the attempt only adds sendmail noise to the output.
add_filter('pre_wp_mail', '__return_false');

$admin = get_user_by('login', 'admin');
if (!$admin instanceof WP_User) {
    WP_CLI::error('the harness admin user is missing');
}
$expiration = time() + HOUR_IN_SECONDS;
$token = WP_Session_Tokens::get_instance($admin->ID)->create($expiration);
// A name => value map: WP_Http_Cookie objects built without a domain are not
// sent, and the request lands on wp-login.php instead.
$cookies = [
    AUTH_COOKIE => wp_generate_auth_cookie($admin->ID, $expiration, 'auth', $token),
    LOGGED_IN_COOKIE => wp_generate_auth_cookie($admin->ID, $expiration, 'logged_in', $token),
];

/** @return array{code:int, body:string, location:string} */
$request = static function (string $method, string $path, array $body = []) use ($cookies): array {
    $response = wp_remote_request(admin_url($path), [
        'method' => $method,
        'cookies' => $cookies,
        'body' => $body,
        'redirection' => 0,
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) {
        throw new RuntimeException($response->get_error_message());
    }

    return [
        'code' => (int) wp_remote_retrieve_response_code($response),
        'body' => (string) wp_remote_retrieve_body($response),
        'location' => (string) wp_remote_retrieve_header($response, 'location'),
    ];
};

/** The status, and where a redirect points, so a failed check says what came back. */
$http = static fn (array $page): string => ' [HTTP ' . $page['code'] . ($page['location'] !== '' ? ' to ' . $page['location'] : '') . ']';

$renders = static fn (array $page): bool => $page['code'] === 200
    && stripos($page['body'], 'critical error') === false
    && stripos($page['body'], 'Fatal error') === false;

$settings = $request('GET', 'admin.php?page=wc-settings&tab=integration&section=beliq');
$check('the settings screen renders' . $http($settings), static fn () => $renders($settings));
$check('the settings screen shows the beliq fields', static fn () => str_contains($settings['body'], 'woocommerce_beliq_apiKey'));

$product = new WC_Product_Simple();
$product->set_name('Admin paths product');
$product->set_regular_price('100.00');
$product->save();

$order = wc_create_order();
$order->set_billing_company('Kaeufer AG');
$order->set_billing_address_1('Teststrasse 1');
$order->set_billing_city('Hamburg');
$order->set_billing_postcode('20095');
$order->set_billing_country('DE');
$order->set_billing_email('einkauf@example.de');
$order->update_meta_data('_billing_vat', 'DE987654321');
$order->add_product($product, 1);
$order->calculate_totals(true);
$order->save();
$orderId = $order->get_id();

$order->update_status('processing');
$order = wc_get_order($orderId);
$check('the automatic generation against a dead API records its failure', static fn () => $order instanceof WC_Order
    && $order->get_meta(OrderNotesRecorder::META_FAILURE) !== '');

$orderPath = 'admin.php?page=wc-orders&action=edit&id=' . $orderId;
$orderPage = $request('GET', $orderPath);
$check('the order screen renders' . $http($orderPage), static fn () => $renders($orderPage));
$check('the beliq box shows the failure', static fn () => str_contains($orderPage['body'], 'id="beliq_invoice"')
    && str_contains($orderPage['body'], 'The automatic e-invoice failed'));

$form = strstr($orderPage['body'], 'value="beliq_generate_invoice"') ?: '';
preg_match('/name="_wpnonce" value="([^"]+)"/', $form, $nonce);
$check('the beliq box carries the generate nonce', static fn () => isset($nonce[1]));

$generate = $request('POST', 'admin-post.php', [
    'action' => 'beliq_generate_invoice',
    'order_id' => (string) $orderId,
    '_wpnonce' => $nonce[1] ?? '',
    '_wp_http_referer' => wp_parse_url(admin_url($orderPath), PHP_URL_PATH) . '?' . wp_parse_url(admin_url($orderPath), PHP_URL_QUERY),
]);
$check('"Generate now" redirects back to the order' . $http($generate), static fn () => $generate['code'] === 302
    && str_contains($generate['location'], 'id=' . $orderId));

$after = $request('GET', $orderPath);
$check('the order screen shows the generation notice' . $http($after), static fn () => $renders($after)
    && str_contains($after['body'], 'beliq e-invoice generation failed'));

if ($failed > 0) {
    WP_CLI::error("$failed admin path check(s) failed");
}
WP_CLI::success('admin paths ran');
