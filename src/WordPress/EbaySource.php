<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use DateTimeImmutable;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\OAuth\TokenClient;
use TaxEbay\Core\Purchases\PurchaseFeed;
use TaxEbay\Core\Purchases\PurchasesClient;
use TaxEbay\Core\Sales\SaleFeed;
use TaxEbay\Core\Sales\SalesClient;
use Taxmod\Core\Import\FetchBatch;
use Taxmod\Core\Import\FetchingSource;

/**
 * The eBay source of the modeller's inbox (D-953): purchases through the Trading API (D-950), sales through the
 * Fulfillment API (D-979). The inbox calls `fetch`
 * on its schedule and on its button; this module keeps no table and no review page of its own.
 */
final class EbaySource implements FetchingSource
{
    private const SALES_STARTED = 'taxmod_ebay_sales_started';

    public function key(): string
    {
        return 'ebay';
    }

    public function label(): string
    {
        return __('eBay', 'taxebay');
    }

    public function interval(): int
    {
        // 0 = not ready: without a connection the schedule stays off, whatever interval he chose (D-981).
        return Settings::connection() === null ? 0 : Settings::fetchInterval();
    }

    /** Failures are thrown: the inbox records the message and shows it. */
    public function fetch(?string $cursor): FetchBatch
    {
        $keys = Settings::appKeys() ?? throw new EbayApiException(__('The app keys are missing.', 'taxebay'));
        $connection = Settings::connection() ?? throw new EbayApiException(__('Not connected to eBay yet.', 'taxebay'));
        $http = WpHttpTransport::budgeted();
        $token = (new TokenClient($keys, $http))->refresh(Settings::vault()->open($connection['refresh']), time())->accessToken;

        $now = new DateTimeImmutable('now');
        $purchases = (new PurchaseFeed(new PurchasesClient($keys->environment, $http)))->run($token, $cursor, $now);
        // The first run after sales came in (0.0.17) reaches back the full 90 days, not only to the purchases' cursor.
        $salesFrom = get_option(self::SALES_STARTED, '') === '' ? null : $cursor;
        $sales = (new SaleFeed(new SalesClient($keys->environment, $http)))->run($token, $salesFrom, $now);
        update_option(self::SALES_STARTED, $now->format(DATE_ATOM), false);
        Plugin::checkDisputes($token);

        return new FetchBatch([...$purchases->proposals, ...$sales], $purchases->cursor);
    }
}
