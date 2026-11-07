<?php declare(strict_types=1);

namespace Beliq\WooCommerce\Retry;

use WC_Order;

/**
 * Shows each failure of the automatic invoice on the order: as an order note,
 * and as order meta the beliq box on the order screen reads.
 */
final class OrderNotesRecorder implements FailureRecorder
{
    public const META_FAILURE = '_beliq_invoice_failure';
    public const META_FAILED_AT = '_beliq_invoice_failed_at';
    public const META_NEXT_ATTEMPT_AT = '_beliq_invoice_next_attempt_at';

    public function recordFailure(int $orderId, string $description, int $at, ?int $nextAttemptAt): void
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof WC_Order) {
            return;
        }

        $order->update_meta_data(self::META_FAILURE, $description);
        $order->update_meta_data(self::META_FAILED_AT, gmdate('c', $at));
        if ($nextAttemptAt === null) {
            $order->delete_meta_data(self::META_NEXT_ATTEMPT_AT);
            $note = sprintf(
                /* translators: %s: the beliq error code or the connection failure */
                __('beliq e-invoice failed: %s. No further automatic attempt; use "Generate now" in the beliq box.', 'beliq-e-invoicing'),
                $description,
            );
        } else {
            $order->update_meta_data(self::META_NEXT_ATTEMPT_AT, gmdate('c', $nextAttemptAt));
            $note = sprintf(
                /* translators: 1: the beliq error code or the connection failure, 2: date and time of the next attempt */
                __('beliq e-invoice failed: %1$s. Next automatic attempt at %2$s.', 'beliq-e-invoicing'),
                $description,
                wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $nextAttemptAt),
            );
        }
        $order->add_order_note($note);
        $order->save();
    }

    public function clear(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            self::clearOn($order);
            $order->save();
        }
    }

    public function stopRetrying(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if ($order instanceof WC_Order) {
            $order->delete_meta_data(self::META_NEXT_ATTEMPT_AT);
            $order->save();
        }
    }

    /** Removes the failure from an order object the caller saves. */
    public static function clearOn(WC_Order $order): void
    {
        $order->delete_meta_data(self::META_FAILURE);
        $order->delete_meta_data(self::META_FAILED_AT);
        $order->delete_meta_data(self::META_NEXT_ATTEMPT_AT);
    }
}
