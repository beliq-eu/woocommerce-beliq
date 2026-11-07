<?php declare(strict_types=1);

namespace Beliq\WooCommerce;

use Beliq\WooCommerce\Admin\OrderActions;
use Beliq\WooCommerce\Admin\OrderMetabox;
use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Integration\InvoiceIntegration;
use Beliq\WooCommerce\Invoice\DocumentStore;
use Beliq\WooCommerce\Invoice\InvoiceGenerator;
use Beliq\WooCommerce\Http\WpHttpClient;
use Beliq\WooCommerce\Order\OrderStatusTrigger;
use Beliq\WooCommerce\Retry\ActionSchedulerRetries;
use Beliq\WooCommerce\Retry\InvoiceAttempts;
use Beliq\WooCommerce\Retry\MysqlOrderLock;
use Beliq\WooCommerce\Retry\OrderMetaRetryStore;
use Beliq\WooCommerce\Retry\OrderNotesRecorder;
use Beliq\WooCommerce\Retry\WcInvoiceGeneration;
use Beliq\Core\Retry\RetrySchedule;

/**
 * Wires the plugin's runtime once WooCommerce is loaded: registers the settings
 * integration, the order-status trigger that generates on the configured status,
 * the Action Scheduler hook that runs a later attempt, and the admin surfaces
 * (metabox, download, and manual regenerate). Everything
 * here is plain hook registration; the work lives in the collaborators.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        $configProvider = new WooPluginConfigProvider();
        $store = new DocumentStore();
        $generator = new InvoiceGenerator($store);

        $attempts = new InvoiceAttempts(
            new OrderMetaRetryStore(),
            new ActionSchedulerRetries(),
            new MysqlOrderLock(),
            new WcInvoiceGeneration(
                $configProvider,
                $generator,
                new InvoiceGenerator($store, new WpHttpClient(RetrySchedule::LATER_ATTEMPT_TIMEOUT_SECONDS)),
            ),
            new OrderNotesRecorder(),
            log: static function (int $orderId, string $message): void {
                wc_get_logger()->error('beliq invoice generation failed: ' . $message, ['source' => 'beliq', 'order_id' => $orderId]);
            },
        );

        add_filter('woocommerce_integrations', [$this, 'registerIntegration']);
        (new OrderStatusTrigger($configProvider, $store, $attempts))->register();
        add_action(ActionSchedulerRetries::HOOK, [$attempts, 'later']);
        (new OrderActions($configProvider, $generator, $store))->register();
        (new OrderMetabox($store))->register();
    }

    /**
     * @param array<int, class-string> $integrations
     * @return array<int, class-string>
     */
    public function registerIntegration(array $integrations): array
    {
        $integrations[] = InvoiceIntegration::class;

        return $integrations;
    }
}
