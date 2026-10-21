<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use DateTimeImmutable;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\OAuth\TokenClient;
use TaxEbay\Core\Purchases\PurchaseFeed;
use TaxEbay\Core\Purchases\PurchasesClient;
use Taxmod\Core\Import\FetchBatch;
use Taxmod\Core\Import\FetchingSource;

/**
 * The eBay source of the modeller's inbox (D-953): purchases through the Trading API (D-950). The inbox calls `fetch`
 * on its schedule and on its button; this module keeps no table and no review page of its own.
 */
final class EbaySource implements FetchingSource
{
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
        return Settings::fetchInterval();
    }

    /** Failures are thrown: the inbox records the message and shows it. */
    public function fetch(?string $cursor): FetchBatch
    {
        $keys = Settings::appKeys() ?? throw new EbayApiException(__('The app keys are missing.', 'taxebay'));
        $connection = Settings::connection() ?? throw new EbayApiException(__('Not connected to eBay yet.', 'taxebay'));
        $http = WpHttpTransport::budgeted();
        $token = (new TokenClient($keys, $http))->refresh(Settings::vault()->open($connection['refresh']), time())->accessToken;

        return (new PurchaseFeed(new PurchasesClient($keys->environment, $http)))->run($token, $cursor, new DateTimeImmutable('now'));
    }
}
