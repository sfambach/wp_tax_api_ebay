<?php declare(strict_types=1);

namespace TaxEbay\Core\Purchases;

use SimpleXMLElement;

/**
 * Turns one Trading API `<Order>` he bought into the proposal shape shared with tax_mail (D-942, D-943), kind
 * «ordered». The seller's user name is the merchant; no address is taken.
 */
final class PurchaseMapper
{
    public const PLATFORM = 'eBay';

    /** @return array<string, mixed> the shared proposal shape; `mixed` because the lines nest */
    public function map(SimpleXMLElement $order): array
    {
        $lines = [];
        $tracking = [];
        $shipped = null;
        $seller = trim((string) ($order->SellerUserID ?? ''));

        foreach ($order->TransactionArray->Transaction ?? [] as $transaction) {
            $lines[] = [
                'title'          => trim((string) $transaction->Item->Title),
                'quantity'       => (float) ((int) $transaction->QuantityPurchased ?: 1),
                'unit_price'     => self::amount($transaction->TransactionPrice ?? null),
                'article_number' => trim((string) $transaction->Item->ItemID) ?: null,
            ];

            foreach ($transaction->ShippingDetails->ShipmentTrackingDetails ?? [] as $detail) {
                $number = trim((string) $detail->ShipmentTrackingNumber);

                if ($number !== '') {
                    $tracking[] = $number;
                }
            }

            $seller = $seller !== '' ? $seller : trim((string) ($transaction->Item->Seller->UserID ?? ''));
            $date = self::day((string) ($transaction->ShippedTime ?? ''));
            $shipped = $shipped === null || ($date !== null && $date < $shipped) ? $date : $shipped;
        }

        $date = self::day((string) ($order->ShippedTime ?? ''));
        $shipped = $shipped ?? $date;

        return [
            'platform'      => self::PLATFORM,
            'order_number'  => trim((string) $order->OrderID),
            'kind'          => 'ordered',
            'order_date'    => self::day((string) $order->CreatedTime),
            'currency'      => (string) ($order->Total['currencyID'] ?? 'EUR') ?: 'EUR',
            'lines'         => $lines,
            'shipping_cost' => self::amount($order->ShippingServiceSelected->ShippingServiceCost ?? null),
            'total'         => self::amount($order->Total ?? null),
            'counterparty'  => $seller === '' ? null : $seller,
            'fees'          => null,
            'shipped_date'  => $shipped,
            'tracking'      => array_values(array_unique($tracking)),
        ];
    }

    /**
     * Every number eBay knows this order by — the mail may have shown the other one.
     *
     * @return list<string>
     */
    public function orderNumbers(SimpleXMLElement $order): array
    {
        return array_values(array_unique(array_filter([
            trim((string) $order->OrderID),
            trim((string) ($order->ExtendedOrderID ?? '')),
        ], static fn (string $number): bool => $number !== '')));
    }

    public function isCancelled(SimpleXMLElement $order): bool
    {
        return in_array((string) $order->OrderStatus, ['Cancelled', 'CancelPending', 'Inactive'], true);
    }

    private static function amount(?SimpleXMLElement $money): ?string
    {
        if ($money === null || ! is_numeric((string) $money)) {
            return null;
        }

        return number_format((float) (string) $money, 2, '.', '');
    }

    private static function day(string $timestamp): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $timestamp) === 1 ? substr($timestamp, 0, 10) : null;
    }
}
