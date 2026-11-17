<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use TaxEbay\Core\Api\EbayApiException;
use Taxmod\Core\Http\HttpTransport;
use Taxmod\WordPress\Http\WpHttpTransport;

/** This module's way to the web: the modeller's one HTTP block (D-1415) with this module's failure and trace header. */
final class ApiTransport
{
    public static function make(): HttpTransport
    {
        return new WpHttpTransport(static fn (string $why): \Throwable => new EbayApiException('eBay not reachable: ' . $why), '');
    }

    /** Every call of the module goes through this: counted and capped per day. */
    public static function budgeted(): HttpTransport
    {
        return new \TaxEbay\Core\Api\CallBudget(self::make(), new OptionCallLedger(), OptionCallLedger::today());
    }
}
