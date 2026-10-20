<?php declare(strict_types=1);

namespace TaxEbay\Core\Sales;

use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpRequest;
use TaxEbay\Core\Api\HttpTransport;

/**
 * Reads his sales from the Sell Fulfillment API: orders changed since a point in time, page by page, and for each
 * shipped order its shipping fulfilments (tracking number, ship date). Read only.
 *
 * ⚠️ Field names follow eBay's documentation; not measured against a live account yet.
 */
final class SalesClient
{
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly Environment $environment,
        private readonly HttpTransport $http,
    ) {
    }

    /**
     * @param string $modifiedSince ISO 8601 UTC, e.g. 2026-10-01T00:00:00.000Z
     * @return list<array<string, mixed>> eBay order objects, each with `_fulfillments` added; `mixed` because eBay's JSON nests
     */
    public function ordersModifiedSince(string $accessToken, string $modifiedSince): array
    {
        $url = $this->environment->apiHost() . '/sell/fulfillment/v1/order?' . http_build_query([
            'filter' => 'lastmodifieddate:[' . $modifiedSince . '..]',
            'limit'  => self::PAGE_SIZE,
        ], '', '&', PHP_QUERY_RFC3986);
        $orders = [];

        while ($url !== '') {
            $page = $this->get($accessToken, $url, 'Reading sales');

            foreach ((array) ($page['orders'] ?? []) as $order) {
                $order['_fulfillments'] = self::isShipped($order) ? $this->fulfillments($accessToken, (string) $order['orderId']) : [];
                $orders[] = $order;
            }

            $url = (string) ($page['next'] ?? '');
        }

        return $orders;
    }

    /** @return list<array<string, mixed>> */
    private function fulfillments(string $accessToken, string $orderId): array
    {
        $answer = $this->get(
            $accessToken,
            $this->environment->apiHost() . '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/shipping_fulfillment',
            'Reading shipment of ' . $orderId
        );

        return array_values((array) ($answer['fulfillments'] ?? []));
    }

    /** @param array<string, mixed> $order */
    private static function isShipped(array $order): bool
    {
        return in_array($order['orderFulfillmentStatus'] ?? '', ['IN_PROGRESS', 'FULFILLED'], true);
    }

    /** @return array<string, mixed> */
    private function get(string $accessToken, string $url, string $what): array
    {
        $response = $this->http->send(new HttpRequest('GET', $url, [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept'        => 'application/json',
        ]));

        if ($response->status !== 200) {
            throw EbayApiException::fromResponse($what, $response);
        }

        return $response->json();
    }
}
