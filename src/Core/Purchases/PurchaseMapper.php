<?php declare(strict_types=1);

namespace TaxEbay\Core\Purchases;

use SimpleXMLElement;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;

/**
 * Turns one Trading API `<Order>` he bought into the modeller's `OrderProposal` (D-952, D-953), kind «ordered»,
 * source «ebay». The seller's user name is the merchant; no address is taken.
 */
final class PurchaseMapper
{
    public const PLATFORM = 'eBay';
    public const SOURCE = 'ebay';

    public function map(SimpleXMLElement $order): OrderProposal
    {
        $lines = [];
        $tracking = [];
        $shipped = null;
        $seller = trim((string) ($order->SellerUserID ?? ''));

        foreach ($order->TransactionArray->Transaction ?? [] as $transaction) {
            $lines[] = new ProposalLine(
                trim((string) $transaction->Item->Title),
                (float) ((int) $transaction->QuantityPurchased ?: 1),
                self::amount($transaction->TransactionPrice ?? null),
                trim((string) $transaction->Item->ItemID) ?: null,
            );

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

        $orderId = trim((string) $order->OrderID);

        return new OrderProposal(
            self::PLATFORM,
            $orderId,
            ProposalKind::Ordered,
            self::day((string) $order->CreatedTime),
            (string) ($order->Total['currencyID'] ?? 'EUR') ?: 'EUR',
            $lines,
            self::amount($order->ShippingServiceSelected->ShippingServiceCost ?? null),
            self::amount($order->Total ?? null),
            $seller === '' ? null : $seller,
            null,
            $shipped,
            array_values(array_unique($tracking)),
            self::SOURCE,
            $orderId,
        );
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
