<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * Sales that go to the same buyer at the same address travel in one parcel: one insert, one tracking number for all
 * of them, shipping paid once (his word, 2026-10-23).
 */
final class Parcels
{
    /**
     * @param list<OpenShipment> $open
     * @return list<non-empty-list<OpenShipment>> in the order of their oldest sale
     */
    public static function of(array $open): array
    {
        $parcels = [];
        // One tracking number is one parcel, even when eBay spells the two addresses differently (2026-10-27).
        $byTracking = [];

        foreach ($open as $sale) {
            $key = self::key($sale);
            $sent = $sale->tracking === '' ? '' : mb_strtolower($sale->buyer) . '|' . strtoupper(trim($sale->tracking));

            if ($sent !== '' && isset($byTracking[$sent])) {
                $key = $byTracking[$sent];
            }

            if ($sent !== '') {
                $byTracking[$sent] ??= $key;
            }

            $parcels[$key][] = $sale;
        }

        return array_values($parcels);
    }

    /** Buyer and address, ignoring case and spacing; a sale without an address stays alone. */
    private static function key(OpenShipment $sale): string
    {
        if ($sale->address === []) {
            return 'order|' . $sale->orderId;
        }

        $normal = static fn (string $text): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($text))) ?? '';

        return $normal($sale->buyer) . '|' . implode('|', array_map($normal, $sale->address));
    }
}
