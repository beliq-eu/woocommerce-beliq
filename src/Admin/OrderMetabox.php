<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Admin;

use Beliq\WooCommerce\Invoice\DocumentStore;
use WC_Order;
use WP_Post;

/**
 * The "beliq e-invoice" box on the order edit screen. It shows whether a document
 * is stored (and when), a capability-checked download button, and a form to
 * generate or regenerate on demand. It is added to whichever order screen is
 * active, so it works under HPOS and the legacy posts store alike. The actual
 * download and generate requests are handled by OrderActions.
 */
final class OrderMetabox
{
    public function __construct(private readonly DocumentStore $store)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox']);
    }

    public function addMetaBox(): void
    {
        $screen = function_exists('wc_get_page_screen_id')
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'beliq_invoice',
            __('beliq e-invoice', 'woocommerce-beliq'),
            [$this, 'render'],
            $screen,
            'side',
            'default',
        );
    }

    /**
     * @param WP_Post|WC_Order $postOrOrder the argument WordPress passes to a
     *        metabox callback: a WC_Order under HPOS, a WP_Post under the legacy
     *        posts store
     */
    public function render(WP_Post|WC_Order $postOrOrder): void
    {
        $order = $postOrOrder instanceof WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID);
        if (!$order instanceof WC_Order) {
            return;
        }

        $orderId = $order->get_id();
        $hasDocument = $this->store->has($order);

        if ($hasDocument) {
            $generatedAt = $this->store->generatedAt($order);
            echo '<p>' . esc_html__('A compliant e-invoice is stored for this order.', 'woocommerce-beliq') . '</p>';
            if ($generatedAt !== null) {
                echo '<p><small>' . esc_html(sprintf(
                    /* translators: %s: generation timestamp */
                    __('Generated %s', 'woocommerce-beliq'),
                    $generatedAt,
                )) . '</small></p>';
            }

            $downloadUrl = wp_nonce_url(
                admin_url('admin-post.php?action=beliq_download_invoice&order_id=' . $orderId),
                OrderActions::NONCE_DOWNLOAD,
            );
            echo '<p><a class="button button-primary" href="' . esc_url($downloadUrl) . '">'
                . esc_html__('Download', 'woocommerce-beliq') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No e-invoice has been generated for this order yet.', 'woocommerce-beliq') . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="beliq_generate_invoice" />';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $orderId) . '" />';
        wp_nonce_field(OrderActions::NONCE_GENERATE);
        $label = $hasDocument
            ? __('Regenerate', 'woocommerce-beliq')
            : __('Generate now', 'woocommerce-beliq');
        echo '<button type="submit" class="button">' . esc_html($label) . '</button>';
        echo '</form>';
    }
}
