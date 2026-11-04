<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * A parcel sent a second time (D-1168): why, the tracking number of the new parcel, the number and stage it had before,
 * and the stock booking of the goods that went out again — a loss. The note lives with the shipping page; eBay knows
 * nothing of it.
 *
 * ```mermaid
 * flowchart LR
 *   S["Versendet / Empfangen"] -->|"Erneut versenden (Grund, Ware)"| O["Offen"]
 *   O --> P["Verpackt"] --> V["Versendet (neue Nummer)"]
 *   O -.->|"zurücknehmen: Storno"| S
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/verkauf.md §6
 */
final class Resends
{
    public const LOST = 'lost';
    public const DAMAGED = 'damaged';
    public const RETURNED = 'returned';
    public const OTHER = 'other';

    public const REASONS = [self::LOST, self::DAMAGED, self::RETURNED, self::OTHER];

    /** A resend note is kept this long; the shipping page reads its orders from eBay as long (D-1168). */
    public const KEEP_DAYS = 90;

    /** Only from these stages a parcel can go out again: before, it has not left. */
    public const FROM = [Stages::SHIPPED, Stages::RECEIVED];

    /**
     * @param array<string, list<array<string, mixed>>> $stored order id → its resends, oldest first; `mixed`: the note
     *                                                            fields below
     * @param list<string> $orders
     * @param array{at: int, reason: string, note: string, tracking: string, previous_tracking: string, previous_stage: string, deal: int, who: string} $entry
     * @return array<string, list<array<string, mixed>>>
     */
    public static function add(array $stored, array $orders, array $entry): array
    {
        if (! in_array($entry['reason'], self::REASONS, true)) {
            $entry['reason'] = self::OTHER;
        }

        $entry['tracking'] = self::cleanNumber($entry['tracking']);

        foreach ($orders as $order) {
            $stored[$order][] = $entry;
        }

        return $stored;
    }

    /**
     * The newest resend of a parcel — the one of its orders that has any.
     *
     * @param array<string, list<array<string, mixed>>> $stored
     * @param list<string> $orders
     * @return array<string, mixed>|null
     */
    public static function latest(array $stored, array $orders): ?array
    {
        $found = null;

        foreach ($orders as $order) {
            $last = $stored[$order][count($stored[$order] ?? []) - 1] ?? null;

            if (is_array($last) && ($found === null || (int) $last['at'] > (int) $found['at'])) {
                $found = $last;
            }
        }

        return $found;
    }

    /** How many times a parcel went out again. @param array<string, list<array<string, mixed>>> $stored @param list<string> $orders */
    public static function count(array $stored, array $orders): int
    {
        return max(0, ...array_map(static fn (string $order): int => count($stored[$order] ?? []), $orders));
    }

    /**
     * The tracking number of the newest parcel, entered for the latest resend — '' while none is entered.
     *
     * @param array<string, list<array<string, mixed>>> $stored
     * @param list<string> $orders
     */
    public static function trackingOf(array $stored, array $orders): string
    {
        return (string) (self::latest($stored, $orders)['tracking'] ?? '');
    }

    /**
     * @param array<string, list<array<string, mixed>>> $stored
     * @param list<string> $orders
     * @return array<string, list<array<string, mixed>>>
     */
    public static function withTracking(array $stored, array $orders, string $tracking): array
    {
        foreach ($orders as $order) {
            $at = count($stored[$order] ?? []) - 1;

            if ($at >= 0) {
                $stored[$order][$at]['tracking'] = self::cleanNumber($tracking);
            }
        }

        return $stored;
    }

    /**
     * Takes the newest resend back: the notes without it, and the note itself, so the caller cancels its booking and
     * restores the stage it had.
     *
     * @param array<string, list<array<string, mixed>>> $stored
     * @param list<string> $orders
     * @return array{0: array<string, list<array<string, mixed>>>, 1: array<string, mixed>|null}
     */
    public static function undo(array $stored, array $orders): array
    {
        $latest = self::latest($stored, $orders);

        if ($latest === null) {
            return [$stored, null];
        }

        foreach ($orders as $order) {
            $last = count($stored[$order] ?? []) - 1;

            if ($last >= 0 && (int) $stored[$order][$last]['at'] === (int) $latest['at']) {
                array_pop($stored[$order]);
            }

            if (($stored[$order] ?? null) === []) {
                unset($stored[$order]);
            }
        }

        return [$stored, $latest];
    }

    /**
     * Notes younger than KEEP_DAYS.
     *
     * @param array<string, list<array<string, mixed>>> $stored
     * @return array<string, list<array<string, mixed>>>
     */
    public static function kept(array $stored, int $now): array
    {
        $kept = [];

        foreach ($stored as $order => $entries) {
            $young = array_values(array_filter($entries, static fn (array $entry): bool => (int) ($entry['at'] ?? 0) > $now - self::KEEP_DAYS * 86400));

            if ($young !== []) {
                $kept[$order] = $young;
            }
        }

        return $kept;
    }

    /** Letters and digits only, as a carrier prints them. */
    public static function cleanNumber(string $number): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number));
    }
}
