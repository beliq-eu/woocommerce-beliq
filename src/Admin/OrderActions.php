<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Admin;

use Beliq\WooCommerce\Config\WooPluginConfigProvider;
use Beliq\WooCommerce\Invoice\DocumentStore;
use Beliq\WooCommerce\Invoice\InvoiceGenerator;
use Throwable;
use WC_Order;

/**
 * The admin entry points for a stored invoice: the capability-checked download,
 * a manual (re)generate posted from the order metabox, and the native
 * WooCommerce "Order actions" dropdown entry. Every request is nonce-checked and
 * gated on the shop-order edit capability. Manual generation forces a rebuild
 * (overwriting an existing document); its outcome is surfaced as an admin notice.
 */
final class OrderActions
{
    public const NONCE_DOWNLOAD = 'beliq_download_invoice';
    public const NONCE_GENERATE = 'beliq_generate_invoice';

    private const CAPABILITY = 'edit_shop_orders';
    private const NOTICE_TRANSIENT_PREFIX = 'beliq_notice_';

    public function __construct(
        private readonly WooPluginConfigProvider $configProvider,
        private readonly InvoiceGenerator $generator,
        private readonly DocumentStore $store,
    ) {
    }

    public function register(): void
    {
        add_filter('woocommerce_order_actions', [$this, 'addOrderAction']);
        add_action('woocommerce_order_action_beliq_generate_invoice', [$this, 'handleOrderAction']);
        add_action('admin_post_beliq_download_invoice', [$this, 'handleDownload']);
        add_action('admin_post_beliq_generate_invoice', [$this, 'handleGenerate']);
        add_action('admin_notices', [$this, 'renderNotice']);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function addOrderAction(array $actions): array
    {
        $actions['beliq_generate_invoice'] = __('Generate beliq e-invoice', 'woocommerce-beliq');

        return $actions;
    }

    /**
     * The native WooCommerce order-action dropdown. WooCommerce verifies its own
     * nonce and capability before dispatching, and redirects with its own notice,
     * so this only does the work and logs a failure.
     */
    public function handleOrderAction(WC_Order $order): void
    {
        try {
            $this->generator->generate($order, $this->configProvider->get(), force: true);
        } catch (Throwable $exception) {
            $this->log($exception, $order->get_id());
        }
    }

    public function handleDownload(): void
    {
        check_admin_referer(self::NONCE_DOWNLOAD);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to download this document.', 'woocommerce-beliq'), '', ['response' => 403]);
        }

        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (!$order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'woocommerce-beliq'), '', ['response' => 404]);
        }

        $path = $this->store->resolvePath($order);
        if ($path === null) {
            wp_die(esc_html__('No document is stored for this order.', 'woocommerce-beliq'), '', ['response' => 404]);
        }

        nocache_headers();
        header('Content-Type: ' . $this->store->contentType($order));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($this->store->downloadName($order)) . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }

    public function handleGenerate(): void
    {
        check_admin_referer(self::NONCE_GENERATE);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to generate this document.', 'woocommerce-beliq'), '', ['response' => 403]);
        }

        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        if (!$order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'woocommerce-beliq'), '', ['response' => 404]);
        }

        $notice = 'success';
        try {
            $result = $this->generator->generate($order, $this->configProvider->get(), force: true);
            if ($result === null) {
                $notice = 'skipped';
            }
        } catch (Throwable $exception) {
            $this->log($exception, $orderId);
            $notice = 'error';
        }

        set_transient(self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), $notice, 60);

        $referer = wp_get_referer();
        wp_safe_redirect($referer !== false ? $referer : admin_url('admin.php?page=wc-orders'));
        exit;
    }

    public function renderNotice(): void
    {
        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        if ($notice === false) {
            return;
        }
        delete_transient($key);

        [$class, $message] = match ($notice) {
            'success' => ['notice-success', __('beliq e-invoice generated.', 'woocommerce-beliq')],
            'skipped' => ['notice-warning', __('No beliq e-invoice was generated: this order is outside the configured scope.', 'woocommerce-beliq')],
            default => ['notice-error', __('beliq e-invoice generation failed. See WooCommerce > Status > Logs (source: beliq).', 'woocommerce-beliq')],
        };

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($message),
        );
    }

    private function log(Throwable $exception, int $orderId): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                'beliq invoice generation failed: ' . $exception->getMessage(),
                ['source' => 'beliq', 'order_id' => $orderId],
            );
        }
    }
}
