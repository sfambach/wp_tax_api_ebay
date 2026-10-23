<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * What one tab of the shipping page shows: search, filters and order, as a WordPress list table would offer them
 * (his word 2026-10-23: «filter und sowas … suche also wie bei wp üblich»).
 */
final class ParcelList
{
    public const LABEL_EBAY = 'ebay';
    public const LABEL_OWN = 'own';
    public const PAID = 'paid';
    public const UNPAID = 'unpaid';
    public const ORDER_BY = ['date', 'buyer', 'total'];

    public function __construct(
        public readonly string $search = '',
        public readonly string $label = '',
        public readonly string $paid = '',
        public readonly string $orderBy = 'date',
        public readonly bool $descending = false,
    ) {
    }

    /**
     * @param list<non-empty-list<OpenShipment>> $parcels
     * @return list<non-empty-list<OpenShipment>>
     */
    public function apply(array $parcels): array
    {
        $kept = array_values(array_filter($parcels, fn (array $parcel): bool => $this->matches($parcel)));
        $key = match ($this->orderBy) {
            'buyer' => static fn (array $parcel): string => mb_strtolower($parcel[0]->buyer),
            'total' => static fn (array $parcel): float => self::total($parcel),
            default => static fn (array $parcel): string => $parcel[0]->orderedAt,
        };
        usort($kept, fn (array $a, array $b): int => ($this->descending ? -1 : 1) * ($key($a) <=> $key($b)));

        return $kept;
    }

    /** @param non-empty-list<OpenShipment> $parcel */
    public static function total(array $parcel): float
    {
        return array_sum(array_map(static fn (OpenShipment $sale): float => (float) $sale->total, $parcel));
    }

    /** @param non-empty-list<OpenShipment> $parcel */
    private function matches(array $parcel): bool
    {
        $labelled = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->labelled, true);
        $paid = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->paid, true);

        if (($this->label === self::LABEL_EBAY && ! $labelled) || ($this->label === self::LABEL_OWN && $labelled)) {
            return false;
        }

        if (($this->paid === self::PAID && ! $paid) || ($this->paid === self::UNPAID && $paid)) {
            return false;
        }

        $search = trim($this->search);

        if ($search === '') {
            return true;
        }

        foreach ($parcel as $sale) {
            $words = [$sale->orderId, $sale->buyer, $sale->tracking, ...$sale->address, ...array_map(static fn (array $item): string => $item['title'] . ' ' . $item['sku'], $sale->items)];

            foreach ($words as $word) {
                if (mb_stripos($word, $search) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
