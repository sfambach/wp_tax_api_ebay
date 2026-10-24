<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * Where a parcel stands on the shipping page: his own note, eBay knows nothing of it (D-985). The tabs follow the
 * order of this list.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/verkauf.md §6
 */
final class Stages
{
    public const OPEN = 'open';
    public const PACKED = 'packed';
    public const SHIPPED = 'shipped';
    public const RECEIVED = 'received';

    public const ALL = [self::OPEN, self::PACKED, self::SHIPPED, self::RECEIVED];

    /** A label bought at eBay with no stage of his stays in «open» this long, then it is taken as sent. */
    public const LABELLED_OPEN_SECONDS = 3 * 86400;

    /**
     * @param non-empty-list<OpenShipment> $parcel
     * @param array<string, array{stage: string, at: int}> $noted order id → his note
     * @return string|null null: an old eBay label he never noted — it shows nowhere
     */
    public static function of(array $parcel, array $noted, int $now): ?string
    {
        foreach ($parcel as $sale) {
            if (isset($noted[$sale->orderId]) && in_array($noted[$sale->orderId]['stage'], self::ALL, true)) {
                return $noted[$sale->orderId]['stage'];
            }
        }

        foreach ($parcel as $sale) {
            $modified = strtotime($sale->modifiedAt);

            if ($sale->labelled && $modified !== false && $modified < $now - self::LABELLED_OPEN_SECONDS) {
                return null;
            }
        }

        return self::OPEN;
    }

    /**
     * The stage the carrier's word moves a parcel to — only ever forward; «announced» and «problem» move nothing.
     *
     * @param string $carrierStage announced|shipped|delivered|problem (DHL module, taxmod_dhl_tracking)
     */
    public static function afterCarrier(string $current, string $carrierStage): ?string
    {
        $target = match ($carrierStage) {
            'shipped'   => self::SHIPPED,
            'delivered' => self::RECEIVED,
            default     => null,
        };

        if ($target === null || array_search($target, self::ALL, true) <= array_search($current, self::ALL, true)) {
            return null;
        }

        return $target;
    }

    public static function next(string $stage): ?string
    {
        $at = array_search($stage, self::ALL, true);

        return $at === false ? null : (self::ALL[$at + 1] ?? null);
    }

    public static function previous(string $stage): ?string
    {
        $at = array_search($stage, self::ALL, true);

        return $at === false || $at === 0 ? null : self::ALL[$at - 1];
    }
}
