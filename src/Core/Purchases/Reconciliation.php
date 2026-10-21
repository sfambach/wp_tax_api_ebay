<?php declare(strict_types=1);

namespace TaxEbay\Core\Purchases;

/**
 * Compares what eBay says he bought with the orders already in the model: which ones the mail import missed
 * (D-950). Matching is by platform and order number, case and spaces ignored — the rule both modules use.
 */
final class Reconciliation
{
    /** @var array<string, true> */
    private readonly array $known;

    /** @param list<string> $knownEbayOrderNumbers order numbers of the model's orders with platform «eBay» */
    public function __construct(array $knownEbayOrderNumbers)
    {
        $known = [];

        foreach ($knownEbayOrderNumbers as $number) {
            $known[self::key($number)] = true;
        }

        $this->known = $known;
    }

    /** @param list<string> $orderNumbers every number eBay knows the order by */
    public function isKnown(array $orderNumbers): bool
    {
        foreach ($orderNumbers as $number) {
            if (isset($this->known[self::key($number)])) {
                return true;
            }
        }

        return false;
    }

    private static function key(string $number): string
    {
        return mb_strtolower(preg_replace('/\s+/', '', $number) ?? $number);
    }
}
