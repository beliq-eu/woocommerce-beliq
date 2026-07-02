<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Invoice;

use Beliq\Core\Service\BeliqClient;
use Beliq\Core\Service\HttpClient;
use Beliq\Core\Service\InvoiceMapper;
use Beliq\WooCommerce\Config\PluginConfig;
use Beliq\WooCommerce\Order\WcOrderData;
use Beliq\WooCommerce\Order\WooOrderAdapter;
use WC_Order;

/**
 * Turns a WooCommerce order into a stored, compliant document. It is the single
 * place the runtime calls to produce an invoice: it maps the order, applies the
 * business-only gate, calls the beliq API, and hands the bytes to the
 * DocumentStore. It does not send, file, or transmit anything.
 *
 * generate() returns the stored file name, or null when the order was skipped
 * (an existing document with force off, or the business-only gate). A beliq API
 * failure surfaces as a BeliqApiException for the caller to log; the caller (the
 * status trigger) is the one that must not let it break checkout.
 */
final class InvoiceGenerator
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly ?HttpClient $http = null,
    ) {
    }

    public function generate(WC_Order $order, PluginConfig $config, bool $force): ?string
    {
        // The automatic trigger never overwrites an existing document; only an
        // explicit regenerate (force) does.
        if (!$force && $this->store->has($order)) {
            return null;
        }

        $source = (new WooOrderAdapter())->toSourceOrder(new WcOrderData($order, $config), $config);

        // Skipping a non-business order under the business-only scope is a
        // deliberate no-op, not an error.
        if (!$config->allowsOrder($source)) {
            return null;
        }

        $body = (new InvoiceMapper())->toGenerateBody(
            $source,
            $config->standard,
            $config->output,
            $config->effectiveProfile(),
        );

        $client = $this->http !== null
            ? new BeliqClient($config->apiKey, $config->baseUrl, $this->http)
            : new BeliqClient($config->apiKey, $config->baseUrl);
        $generated = $client->generate($body);

        $extension = str_contains($generated['contentType'], 'pdf') ? 'pdf' : 'xml';

        return $this->store->store(
            $order,
            $generated['bytes'],
            $generated['contentType'],
            $extension,
            $generated['meta'],
        );
    }
}
