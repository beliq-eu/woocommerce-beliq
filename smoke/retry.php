<?php
/**
 * Retry smoke, run inside the wpcli container via `wp eval-file` by
 * `./run.sh retry`. The store calls a stub beliq API on the host (stub-router.php)
 * that answers from a script, so each case sets what beliq answers and then reads
 * what the store did: the order meta, the order notes, the Action Scheduler queue,
 * and the stub's own request log.
 *
 * Time is not waited out: a case brings the next attempt forward (the order's
 * retry state and its queued action both say "due now") and then runs the queue,
 * the way a later minute would. The schedule's real times are covered by the
 * unit tests.
 */

use Beliq\Core\Retry\RetrySchedule;
use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Retry\ActionSchedulerRetries;
use Beliq\WooCommerce\Retry\OrderNotesRecorder;

// wp eval-file includes this file inside a method scope; see smoke.php.
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

$GLOBALS['STUB'] = getenv('BELIQ_BASE_URL') ?: '';
if ($GLOBALS['STUB'] === '') {
    WP_CLI::error('BELIQ_BASE_URL (the stub) is not set in the wpcli environment.');
}

const DB_DOWN = ['status' => 503, 'headers' => ['Content-Type' => 'application/json', 'Retry-After' => '30'], 'body' => '{"success":false,"error":{"code":"DATABASE_UNAVAILABLE","message":"The database is unavailable."}}'];
const INVALID = ['status' => 422, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"success":false,"error":{"code":"INVALID_INVOICE","message":"The invoice is not valid."}}'];
const INVOICE = ['status' => 200, 'headers' => ['Content-Type' => 'application/xml'], 'body' => '<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>'];

function stub_script(array $responses): void
{
    $res = wp_remote_post($GLOBALS['STUB'] . '/__stub/script', ['body' => wp_json_encode($responses), 'timeout' => 5]);
    if (is_wp_error($res) || wp_remote_retrieve_body($res) !== 'ok') {
        WP_CLI::error('Could not script the stub.');
    }
}

/** @return list<array{n: int, path: string}> */
function stub_generate_calls(): array
{
    $res = wp_remote_get($GLOBALS['STUB'] . '/__stub/requests', ['timeout' => 5]);
    $all = is_wp_error($res) ? [] : (json_decode(wp_remote_retrieve_body($res), true) ?: []);

    return array_values(array_filter($all, static fn (array $r): bool => str_starts_with($r['path'], '/v1/generate')));
}

function retry_state(int $orderId): ?array
{
    $order = wc_get_order($orderId);
    $state = json_decode((string) $order->get_meta('_beliq_retry_state'), true);

    return is_array($state) ? $state : null;
}

/** @return list<int> the scheduled times of the order's pending actions */
function pending_actions(int $orderId): array
{
    $ids = as_get_scheduled_actions([
        'hook' => ActionSchedulerRetries::HOOK,
        'args' => ['order_id' => $orderId],
        'group' => ActionSchedulerRetries::GROUP,
        'status' => ActionScheduler_Store::STATUS_PENDING,
    ], 'ids');
    $store = ActionScheduler::store();

    return array_map(static fn ($id): int => $store->fetch_action($id)->get_schedule()->get_date()->getTimestamp(), $ids);
}

/** The next attempt is due now: in the order's retry state and in its queued action. */
function bring_forward(int $orderId): void
{
    $order = wc_get_order($orderId);
    $state = retry_state($orderId);
    $state['nextAttemptAt'] = time() - 1;
    $order->update_meta_data('_beliq_retry_state', wp_json_encode($state));
    $order->save();
    as_unschedule_all_actions(ActionSchedulerRetries::HOOK, ['order_id' => $orderId], ActionSchedulerRetries::GROUP);
    as_schedule_single_action(time() - 1, ActionSchedulerRetries::HOOK, ['order_id' => $orderId], ActionSchedulerRetries::GROUP);
}

/** A separate PHP process, as a cron run is. Returns its exit code. */
function wp_process(string $args, string $prefix = ''): int
{
    exec($prefix . 'wp --path=/var/www/html ' . $args . ' >/dev/null 2>&1', $out, $code);

    return $code;
}

function run_queue_cli(bool $force = false): int
{
    return wp_process('action-scheduler run --hooks=' . ActionSchedulerRetries::HOOK . ($force ? ' --force' : ''));
}

/** The page-load runner: WP-Cron's spawn, called the way a visitor's request triggers it. */
function run_queue_wp_cron(): void
{
    // Action Scheduler's own WP-Cron event, made due now, as it is once a minute.
    wp_clear_scheduled_hook('action_scheduler_run_queue', ['WP Cron']);
    wp_schedule_event(time() - 1, 'every_minute', 'action_scheduler_run_queue', ['WP Cron']);
    // What spawn_cron() does on a page load: take the cron lock, then call
    // wp-cron.php with it. The site URL (localhost:8091) does not resolve inside
    // the containers, so the call goes to the web container by its own name.
    $lock = sprintf('%.22F', microtime(true));
    set_transient('doing_cron', $lock);
    wp_remote_get('http://wordpress/wp-cron.php?doing_wp_cron=' . $lock, ['timeout' => 30, 'blocking' => true]);
}

function invoice_files(int $orderId): array
{
    $uploads = wp_upload_dir();

    return glob(untrailingslashit($uploads['basedir']) . '/beliq-invoices/beliq-invoice-' . $orderId . '-*') ?: [];
}

function beliq_notes(int $orderId): array
{
    return array_values(array_filter(
        array_map(static fn ($n) => $n->content, wc_get_order_notes(['order_id' => $orderId])),
        static fn (string $c): bool => str_starts_with($c, 'beliq e-invoice failed'),
    ));
}

function fresh(int $orderId): WC_Order
{
    return wc_get_order($orderId);
}

// Store setup, as smoke.php: taxes on, a German seller, XRechnung as XML.
update_option('woocommerce_calc_taxes', 'yes');
update_option('woocommerce_prices_include_tax', 'no');
update_option('woocommerce_tax_based_on', 'billing');
update_option('woocommerce_default_country', 'DE:BE');
WC_Tax::_insert_tax_rate([
    'tax_rate_country' => 'DE', 'tax_rate_state' => '', 'tax_rate' => '19.0000', 'tax_rate_name' => 'VAT-DE',
    'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '',
]);
update_option(WooPluginConfigProvider::OPTION, [
    'enabled' => 'yes',
    'apiKey' => 'blq_stub',
    'baseUrl' => $GLOBALS['STUB'],
    'standard' => 'xrechnung',
    'profile' => 'en16931',
    'output' => 'xml',
    'businessOnly' => 'yes',
    'triggerEvent' => 'processing',
    'zeroRateCategory' => 'Z',
    'paymentMeansCode' => '58',
    'buyerVatMetaKey' => '_billing_vat',
    'buyerReferenceMetaKey' => '',
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
]);
$product = new WC_Product_Simple();
$product->set_name('Retry widget');
$product->set_regular_price('100.00');
$product->set_tax_status('taxable');
$product->save();

/** A B2B order, paid: moving it to processing fires the trigger in this process. */
function paid_order(WC_Product $product): int
{
    $order = wc_create_order();
    $order->set_billing_first_name('Max');
    $order->set_billing_last_name('Beispiel');
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
    $order->update_status('processing');

    return $order->get_id();
}

WP_CLI::log('== An outage at payment, then beliq answers ==');
stub_script([DB_DOWN, DB_DOWN, DB_DOWN, INVOICE]);
$id = paid_order($product);
check(count(stub_generate_calls()) === 1, 'the status change made one attempt');
check(invoice_files($id) === [], 'no invoice yet');
check(fresh($id)->get_meta(OrderNotesRecorder::META_FAILURE) === 'DATABASE_UNAVAILABLE (HTTP 503)', 'the failure is on the order');
check(fresh($id)->get_meta(OrderNotesRecorder::META_NEXT_ATTEMPT_AT) !== '', 'with its next attempt');
check(count(beliq_notes($id)) === 1, 'and as an order note');
$pending = pending_actions($id);
$state = retry_state($id);
check(count($pending) === 1 && $state !== null && $pending[0] === $state['nextAttemptAt'], 'one queued action, due when the retry state says');
check($state !== null && $state['nextAttemptAt'] - $state['firstAttemptAt'] === RetrySchedule::LATER_ATTEMPT_OFFSETS[0], 'the first later attempt is due about 2 minutes on');

WP_CLI::log('-- the queue runs before the attempt is due: nothing happens --');
run_queue_cli();
check(count(stub_generate_calls()) === 1, 'no call before the slot');

WP_CLI::log('-- no queue runner: the order waits --');
bring_forward($id);
check(count(stub_generate_calls()) === 1 && invoice_files($id) === [], 'a due attempt does nothing until a runner runs');

WP_CLI::log('-- the CLI runner, then the page-load runner, then the CLI runner --');
run_queue_cli();
check(count(stub_generate_calls()) === 2, 'the CLI runner made the second attempt');
check(count(pending_actions($id)) === 1, 'still one queued action');
bring_forward($id);
run_queue_wp_cron();
check(count(stub_generate_calls()) === 3, 'WP-Cron made the third attempt');
bring_forward($id);
run_queue_cli();
$calls = stub_generate_calls();
check(count($calls) === 4, 'the fourth attempt met beliq answering');
check(count(invoice_files($id)) === 1, 'one invoice is stored');
$order = fresh($id);
check($order->get_meta('_beliq_invoice_download_name') === 'invoice-' . $order->get_order_number() . '.xml', 'named by the plain order number');
check($order->get_meta(OrderNotesRecorder::META_FAILURE) === '', 'the failure is cleared');
check(retry_state($id) === null && pending_actions($id) === [], 'no retry state and nothing queued');
run_queue_cli();
check(count(stub_generate_calls()) === 4, 'nothing more is sent');

WP_CLI::log('== beliq rejects the invoice ==');
stub_script([INVALID]);
$id = paid_order($product);
check(count(stub_generate_calls()) === 1, 'one attempt');
check(fresh($id)->get_meta(OrderNotesRecorder::META_FAILURE) === 'INVALID_INVOICE (HTTP 422)', 'the rejection is on the order');
check(fresh($id)->get_meta(OrderNotesRecorder::META_NEXT_ATTEMPT_AT) === '', 'with no next attempt');
check(retry_state($id) === null && pending_actions($id) === [], 'nothing queued');
run_queue_cli();
check(count(stub_generate_calls()) === 1, 'and nothing retried');

WP_CLI::log('== a runner killed mid-call ==');
stub_script([DB_DOWN, INVOICE + ['sleep' => 20], INVOICE]);
$id = paid_order($product);
bring_forward($id);
wp_process('action-scheduler run --hooks=' . ActionSchedulerRetries::HOOK, 'timeout -s KILL 5 ');
check(count(stub_generate_calls()) === 2, 'the killed runner had sent the second attempt');
check(invoice_files($id) === [], 'and stored nothing');
$state = retry_state($id);
$pending = pending_actions($id);
check($state !== null && $state['laterAttempts'] === 1, 'the retry state counts the attempt it was making');
check(count($pending) === 1 && $pending[0] === $state['nextAttemptAt'] && $state['nextAttemptAt'] >= time() + 60, 'and the next attempt was queued before the call');
bring_forward($id);
// Action Scheduler itself holds new batches while the dead runner's claim is
// open, until its own timeout releases it (5 minutes by default). That wait is
// Action Scheduler's; the plugin's part is that the next attempt is queued.
check(run_queue_cli() !== 0, "Action Scheduler holds the queue while the dead runner's claim is open");
check(count(stub_generate_calls()) === 2, 'so nothing is sent yet');
run_queue_cli(force: true);
check(count(invoice_files($id)) === 1, 'the next runner stored the invoice');
check(count(stub_generate_calls()) === 3, 'with one more call');

WP_CLI::log('== two runs at once for one order ==');
stub_script([DB_DOWN, INVOICE + ['sleep' => 4]]);
$id = paid_order($product);
bring_forward($id);
$command = 'wp --path=/var/www/html eval ' . escapeshellarg('do_action("' . ActionSchedulerRetries::HOOK . '", ' . $id . ');');
$a = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
$b = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
proc_close($a);
proc_close($b);
check(count(stub_generate_calls()) === 2, 'one of the two called beliq');
check(count(invoice_files($id)) === 1, 'one invoice is stored');

WP_CLI::log('');
WP_CLI::log(sprintf('%d passed, %d failed', $GLOBALS['SMOKE_PASSES'], count($GLOBALS['SMOKE_FAILURES'])));
if ($GLOBALS['SMOKE_FAILURES'] !== []) {
    WP_CLI::error('Retry smoke failed: ' . implode('; ', $GLOBALS['SMOKE_FAILURES']));
}
WP_CLI::success('Retry smoke passed.');
