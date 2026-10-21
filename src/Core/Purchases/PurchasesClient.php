<?php declare(strict_types=1);

namespace TaxEbay\Core\Purchases;

use DateTimeImmutable;
use DateTimeZone;
use SimpleXMLElement;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpRequest;
use TaxEbay\Core\Api\HttpTransport;

/**
 * Reads his purchases through the Trading API (`GetOrders`, role «Buyer») — the only eBay interface that returns
 * what a private account bought (D-950). XML, with the OAuth user token in `X-EBAY-API-IAF-TOKEN`.
 *
 * ⚠️ Not measured: element names follow eBay's documentation; how far back eBay answers (documented: 90 days) and
 * whether a private account gets an answer at all is unknown until the keys exist.
 */
final class PurchasesClient
{
    public const MAX_DAYS_BACK = 90;
    private const SITE_GERMANY = '77';
    private const COMPATIBILITY_LEVEL = '1193';
    private const PAGE_SIZE = 100;

    /** 2,000 orders per run is far more than he buys in 90 days; a page loop that never ends stops here. */
    private const MAX_PAGES = 20;

    public function __construct(
        private readonly Environment $environment,
        private readonly HttpTransport $http,
    ) {
    }

    /** @return list<SimpleXMLElement> one `<Order>` each */
    public function ordersCreatedBetween(string $accessToken, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $orders = [];
        $page = 1;

        do {
            $answer = $this->call($accessToken, $this->requestBody($from, $to, $page));

            foreach ($answer->OrderArray->Order ?? [] as $order) {
                $orders[] = $order;
            }

            $page++;
        } while ((string) $answer->HasMoreOrders === 'true' && $page <= self::MAX_PAGES);

        return $orders;
    }

    private function requestBody(DateTimeImmutable $from, DateTimeImmutable $to, int $page): string
    {
        $utc = new DateTimeZone('UTC');
        $time = static fn (DateTimeImmutable $at): string => $at->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<GetOrdersRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            . '<OrderRole>Buyer</OrderRole>'
            . '<CreateTimeFrom>' . $time($from) . '</CreateTimeFrom>'
            . '<CreateTimeTo>' . $time($to) . '</CreateTimeTo>'
            . '<DetailLevel>ReturnAll</DetailLevel>'
            . '<Pagination><EntriesPerPage>' . self::PAGE_SIZE . '</EntriesPerPage><PageNumber>' . $page . '</PageNumber></Pagination>'
            . '</GetOrdersRequest>';
    }

    private function call(string $accessToken, string $body): SimpleXMLElement
    {
        $response = $this->http->send(new HttpRequest('POST', $this->environment->apiHost() . '/ws/api.dll', [
            'Content-Type'                   => 'text/xml',
            'X-EBAY-API-CALL-NAME'           => 'GetOrders',
            'X-EBAY-API-SITEID'              => self::SITE_GERMANY,
            'X-EBAY-API-COMPATIBILITY-LEVEL' => self::COMPATIBILITY_LEVEL,
            'X-EBAY-API-IAF-TOKEN'           => $accessToken,
        ], $body));

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new EbayApiException(sprintf('Reading purchases: HTTP %d, no XML answer', $response->status));
        }

        if (! in_array((string) $xml->Ack, ['Success', 'Warning'], true)) {
            throw new EbayApiException('Reading purchases: ' . trim((string) ($xml->Errors->LongMessage ?? $xml->Errors->ShortMessage ?? 'eBay said ' . $xml->Ack)));
        }

        return $xml;
    }
}
