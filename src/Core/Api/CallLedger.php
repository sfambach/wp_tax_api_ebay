<?php declare(strict_types=1);

namespace TaxEbay\Core\Api;

/**
 * Where the calls of the current day are counted; the boundary keeps them in an option.
 */
interface CallLedger
{
    /** @return array<string, int> API family => calls on $day */
    public function counts(string $day): array;

    /** @param array<string, int> $counts */
    public function store(string $day, array $counts): void;
}
