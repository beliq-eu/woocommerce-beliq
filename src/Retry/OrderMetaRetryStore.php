<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

use Beliq\Core\Retry\RetryState;
use WC_Order;

/** The retry state in order meta, through the CRUD API, so it works under HPOS and the posts store alike. */
final class OrderMetaRetryStore implements RetryStore
{
    private const META_STATE = '_beliq_retry_state';
    private const META_LAST_ERROR = '_beliq_retry_last_error';

    public function find(int $orderId): ?RetryState
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return null;
        }
        $state = json_decode((string) $order->get_meta(self::META_STATE), true);
        if (!is_array($state)) {
            return null;
        }

        return new RetryState(
            (int) ($state['firstAttemptAt'] ?? 0),
            (int) ($state['laterAttempts'] ?? 0),
            (int) ($state['countRetries'] ?? 0),
            (int) ($state['nextAttemptAt'] ?? 0),
        );
    }

    public function lastError(int $orderId): ?string
    {
        $order = wc_get_order($orderId);
        $error = $order instanceof WC_Order ? (string) $order->get_meta(self::META_LAST_ERROR) : '';

        return $error === '' ? null : $error;
    }

    public function save(int $orderId, RetryState $state, ?string $lastError): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }
        $order->update_meta_data(self::META_STATE, (string) wp_json_encode([
            'firstAttemptAt' => $state->firstAttemptAt,
            'laterAttempts' => $state->laterAttempts,
            'countRetries' => $state->countRetries,
            'nextAttemptAt' => $state->nextAttemptAt,
        ]));
        if ($lastError === null) {
            $order->delete_meta_data(self::META_LAST_ERROR);
        } else {
            $order->update_meta_data(self::META_LAST_ERROR, $lastError);
        }
        $order->save();
    }

    public function delete(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }
        $order->delete_meta_data(self::META_STATE);
        $order->delete_meta_data(self::META_LAST_ERROR);
        $order->save();
    }
}
