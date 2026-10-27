<?php declare(strict_types=1);

namespace TaxEbay\Core\Sales;

/**
 * What eBay paid back to the buyer of one order, and what is left of the money. A refund lowers what he received:
 * a combined parcel gets its second postage back (2026-10-27).
 */
final class Refunds
{
    /** eBay states for a refund that is under way or done; a failed one paid nothing back. */
    private const COUNTED = ['PENDING', 'REFUNDED'];

    /**
     * Sum of the refunds in cents.
     *
     * @param array<string, mixed> $order a Fulfillment API order; `mixed` because eBay's JSON nests
     */
    public static function cents(array $order): int
    {
        $cents = 0;

        foreach ((array) ($order['paymentSummary']['refunds'] ?? []) as $refund) {
            $value = $refund['amount']['value'] ?? null;

            if (is_numeric($value) && in_array(strtoupper((string) ($refund['refundStatus'] ?? '')), self::COUNTED, true)) {
                $cents += (int) round((float) $value * 100);
            }
        }

        return $cents;
    }

    /**
     * The order total less the refunds, as a decimal string; null when eBay gives no total.
     *
     * @param array<string, mixed> $order
     */
    public static function netTotal(array $order): ?string
    {
        $value = $order['pricingSummary']['total']['value'] ?? null;

        if (! is_numeric($value)) {
            return null;
        }

        return number_format(((int) round((float) $value * 100) - self::cents($order)) / 100, 2, '.', '');
    }

    /** Paid counts until all of it went back: a partial refund leaves a paid order. */
    public static function isPaid(string $paymentStatus): bool
    {
        return in_array($paymentStatus, ['PAID', 'PARTIALLY_REFUNDED'], true);
    }
}
