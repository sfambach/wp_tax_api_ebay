<?php declare(strict_types=1);

namespace TaxEbay\Core\Sales;

/**
 * Turns one eBay order into a proposal in the format shared with tax_mail (`OrderProposal::toArray()` there,
 * D-942/D-943), kind «sold». The buyer's name and address are never taken — only the eBay user name (D-934).
 *
 * Money stays a decimal string; a line's unit price is its line cost divided by its quantity.
 */
final class SaleMapper
{
    public const PLATFORM = 'eBay';

    /**
     * @param array<string, mixed> $order as SalesClient returned it
     * @return array<string, mixed> the shared proposal shape; `mixed` because the lines nest
     */
    public function map(array $order): array
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

        return [
            'platform'      => self::PLATFORM,
            'order_number'  => (string) ($order['orderId'] ?? ''),
            'kind'          => 'sold',
            'order_date'    => self::day($order['creationDate'] ?? null),
            'currency'      => (string) ($pricing['total']['currency'] ?? 'EUR'),
            'lines'         => array_map(self::line(...), array_values((array) ($order['lineItems'] ?? []))),
            'shipping_cost' => self::amount($pricing['deliveryCost'] ?? null),
            'total'         => self::amount($pricing['total'] ?? null),
            'counterparty'  => isset($order['buyer']['username']) ? (string) $order['buyer']['username'] : null,
            'fees'          => self::amount($order['totalMarketplaceFee'] ?? null),
            'shipped_date'  => $shipped,
            'tracking'      => array_values(array_unique($tracking)),
        ];
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

    /**
     * @param array<string, mixed> $item
     * @return array{title: string, quantity: float, unit_price: ?string, article_number: ?string}
     */
    private static function line(array $item): array
    {
        $quantity = (int) ($item['quantity'] ?? 1) ?: 1;
        $cost = self::amount($item['lineItemCost'] ?? null);

        return [
            'title'          => (string) ($item['title'] ?? ''),
            'quantity'       => (float) $quantity,
            'unit_price'     => $cost === null ? null : self::divide($cost, $quantity),
            'article_number' => isset($item['legacyItemId']) ? (string) $item['legacyItemId'] : null,
        ];
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
