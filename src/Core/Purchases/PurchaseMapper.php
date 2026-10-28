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
    private const ORDER_NUMBER = '/^\d{2}-\d{5}-\d{5}$/';

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

        $orderId = $this->orderNumber($order) ?: trim((string) $order->OrderID);

        return new OrderProposal(
            self::PLATFORM,
            $orderId,
            ProposalKind::Ordered,
            self::day((string) $order->CreatedTime),
            (string) ($order->Total['currencyID'] ?? 'EUR') ?: 'EUR',
            $lines,
            self::shipping($order, $lines),
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
     * The order number eBay shows him and puts in its mails, «12-15342-88258»; '' while eBay has none yet.
     *
     * Until an order is paid, eBay may hand out only the item-transaction id («237125704187-10085947961312») and give
     * the real number later — proposing it then filed the same purchase twice, once from the mail (2026-10-28).
     */
    public function orderNumber(SimpleXMLElement $order): string
    {
        foreach ([$order->OrderID ?? null, $order->ExtendedOrderID ?? null] as $candidate) {
            $number = trim((string) $candidate);

            if (preg_match(self::ORDER_NUMBER, $number) === 1) {
                return $number;
            }
        }

        return '';
    }

    /** True while eBay knows the order only by its item-transaction id: it waits until the real number comes. */
    public function awaitsNumber(SimpleXMLElement $order): bool
    {
        return $this->orderNumber($order) === '' && preg_match('/^\d+-\d+$/', trim((string) $order->OrderID)) === 1;
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

    /**
     * What he paid for shipping: the total less the items. The listed shipping cost is per listing and stays the same
     * when the seller combines the shipping of several items into one parcel.
     *
     * @param list<ProposalLine> $lines
     */
    private static function shipping(SimpleXMLElement $order, array $lines): ?string
    {
        $listed = self::amount($order->ShippingServiceSelected->ShippingServiceCost ?? null);
        $total = self::amount($order->Total ?? null);
        $cents = 0;

        foreach ($lines as $line) {
            if ($line->unitPrice === null) {
                return $listed;
            }

            $cents += (int) round((float) $line->unitPrice * 100) * (int) ($line->quantity ?? 1);
        }

        if ($total === null || $lines === []) {
            return $listed;
        }

        $rest = (int) round((float) $total * 100) - $cents;

        return $rest < 0 ? $listed : number_format($rest / 100, 2, '.', '');
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
