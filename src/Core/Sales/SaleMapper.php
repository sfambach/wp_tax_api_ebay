<?php declare(strict_types=1);

namespace TaxEbay\Core\Sales;

use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;

/**
 * Turns one eBay order into the modeller's `OrderProposal` (D-952, D-953), kind «sold», source «ebay». The buyer's name and address are never taken — only the eBay user name (D-934).
 *
 * Money stays a decimal string; a line's unit price is its line cost divided by its quantity.
 */
final class SaleMapper
{
    public const PLATFORM = 'eBay';
    public const SOURCE = 'ebay';

    /**
     * @param array<string, mixed> $order as SalesClient returned it
     */
    public function map(array $order): OrderProposal
    {
        $pricing = (array) ($order['pricingSummary'] ?? []);
        $fulfillments = (array) ($order['_fulfillments'] ?? []);
        $tracking = [];
        $shipped = null;

        foreach ($fulfillments as $fulfillment) {
            $number = trim((string) ($fulfillment['shipmentTrackingNumber'] ?? ''));

            if ($number !== '') {
                $tracking[] = $number;
            }

            $date = self::day($fulfillment['shippedDate'] ?? null);
            $shipped = $shipped === null || ($date !== null && $date < $shipped) ? $date : $shipped;
        }

        $orderId = (string) ($order['orderId'] ?? '');

        return new OrderProposal(
            self::PLATFORM,
            $orderId,
            ProposalKind::Sold,
            self::day($order['creationDate'] ?? null),
            (string) ($pricing['total']['currency'] ?? 'EUR'),
            array_map(self::line(...), array_values((array) ($order['lineItems'] ?? []))),
            self::amount($pricing['deliveryCost'] ?? null),
            // What he received: a refund (postage of a combined parcel) lowers it.
            Refunds::netTotal($order),
            isset($order['buyer']['username']) ? (string) $order['buyer']['username'] : null,
            self::amount($order['totalMarketplaceFee'] ?? null),
            $shipped,
            array_values(array_unique($tracking)),
            self::SOURCE,
            $orderId,
        );
    }

    /**
     * True when eBay says the order was cancelled — such a sale is not proposed.
     *
     * @param array<string, mixed> $order
     */
    public function isCancelled(array $order): bool
    {
        return in_array($order['cancelStatus']['cancelState'] ?? '', ['CANCELED', 'CANCELLED'], true);
    }

    /** @param array<string, mixed> $item */
    private static function line(array $item): ProposalLine
    {
        $quantity = (int) ($item['quantity'] ?? 1) ?: 1;
        $cost = self::amount($item['lineItemCost'] ?? null);

        return new ProposalLine(
            (string) ($item['title'] ?? ''),
            (float) $quantity,
            $cost === null ? null : self::divide($cost, $quantity),
            // His own SKU (`taxmod-<offer>`, D-976) names the offer in the model; without one the eBay item id.
            ($item['sku'] ?? '') !== '' ? (string) $item['sku'] : (isset($item['legacyItemId']) ? (string) $item['legacyItemId'] : null),
        );
    }

    private static function amount(mixed $money): ?string
    {
        if (! is_array($money) || ! isset($money['value']) || ! is_numeric($money['value'])) {
            return null;
        }

        return number_format((float) $money['value'], 2, '.', '');
    }

    /** Cents, so 10.00 / 3 gives 3.33 and not a float tail. */
    private static function divide(string $amount, int $by): string
    {
        $cents = (int) round(((float) $amount) * 100);

        return number_format(round($cents / $by) / 100, 2, '.', '');
    }

    private static function day(mixed $timestamp): ?string
    {
        return is_string($timestamp) && preg_match('/^\d{4}-\d{2}-\d{2}/', $timestamp) === 1 ? substr($timestamp, 0, 10) : null;
    }
}
