<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use TaxEbay\Core\Api\CallLedger;

/**
 * Today's call counts in one option; a new day starts from zero.
 */
final class OptionCallLedger implements CallLedger
{
    public const OPTION = 'taxmod_ebay_calls';

    public function counts(string $day): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) && ($stored['day'] ?? '') === $day ? array_map('intval', (array) ($stored['counts'] ?? [])) : [];
    }

    public function store(string $day, array $counts): void
    {
        update_option(self::OPTION, ['day' => $day, 'counts' => $counts], false);
    }

    public static function today(): string
    {
        return gmdate('Y-m-d');
    }
}
