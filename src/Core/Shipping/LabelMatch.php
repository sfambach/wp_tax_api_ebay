<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * Which parcel a DHL label belongs to: the recipient's name and postcode printed on it (LabelText) against the
 * address eBay gives. DHL's mail itself names no order.
 */
final class LabelMatch
{
    /**
     * @param list<string> $labelLines
     * @param non-empty-list<OpenShipment> $parcel
     */
    public static function fits(array $labelLines, array $parcel): bool
    {
        $address = $parcel[0]->address;

        if ($address === []) {
            return false;
        }

        $label = array_map(self::normal(...), $labelLines);
        $name = self::normal($address[0]);
        $postcode = '';

        foreach (array_slice($address, 1) as $line) {
            if (preg_match('/\b(\d{4,5})\b/', $line, $digits) === 1) {
                $postcode = $digits[1];
            }
        }

        // DHL may put more on the name line (a company, «c/o», a second first name) — the whole eBay name must stand in it.
        $hasName = $name !== '' && array_filter($label, static fn (string $line): bool => str_contains(' ' . $line . ' ', ' ' . $name . ' ')) !== [];
        $hasPostcode = $postcode === '' || array_filter($label, static fn (string $line): bool => str_starts_with($line, $postcode . ' ')) !== [];

        return $hasName && $hasPostcode;
    }

    private static function normal(string $text): string
    {
        $upper = mb_strtoupper(str_replace('ß', 'SS', $text), 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $upper)));
    }
}
