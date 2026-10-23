<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpRequest;
use TaxEbay\Core\Api\HttpTransport;

/**
 * The sales he has not shipped yet, and the call that tells eBay a parcel left (Sell Fulfillment API, D-976).
 *
 * ⚠️ Field names and carrier codes follow eBay's documentation; not measured against a live sale yet.
 */
final class ShippingClient
{
    private const PAGE_SIZE = 50;
    private const MAX_PAGES = 4;

    /** eBay's carrier codes for the services he uses in Germany. */
    public const CARRIERS = ['DHL' => 'DHL', 'DEUTSCHE_POST' => 'Deutsche Post', 'HERMES' => 'Hermes', 'DPD' => 'DPD', 'GLS' => 'GLS', 'UPS' => 'UPS'];

    public function __construct(
        private readonly Environment $environment,
        private readonly HttpTransport $http,
    ) {
    }

    /**
     * Not shipped yet, plus — when `$labelledSince` is given — orders he bought the eBay label for since then: eBay
     * counts those as shipped, but the parcel still needs packing and its insert.
     *
     * @param string|null $labelledSince ISO 8601 UTC
     * @return list<OpenShipment> oldest first
     */
    public function open(string $accessToken, ?string $labelledSince = null): array
    {
        $open = $this->read($accessToken, 'orderfulfillmentstatus:{NOT_STARTED|IN_PROGRESS}');

        if ($labelledSince !== null) {
            // eBay accepts only {NOT_STARTED|IN_PROGRESS} or {FULFILLED|IN_PROGRESS}; IN_PROGRESS came with the first read.
            $recent = $this->read($accessToken, 'lastmodifieddate:[' . $labelledSince . '..],orderfulfillmentstatus:{FULFILLED|IN_PROGRESS}');
            $labelled = array_filter($recent, static fn (OpenShipment $sale): bool => $sale->labelled);
            $open = [...$open, ...array_map(fn (OpenShipment $sale): OpenShipment => $sale->withTracking($this->tracking($accessToken, $sale->orderId)), $labelled)];
        }

        usort($open, static fn (OpenShipment $a, OpenShipment $b): int => strcmp($a->orderedAt, $b->orderedAt));

        return $open;
    }

    /** @return list<OpenShipment> */
    private function read(string $accessToken, string $filter): array
    {
        $url = $this->environment->apiHost() . '/sell/fulfillment/v1/order?' . http_build_query([
            'filter' => $filter,
            'limit'  => self::PAGE_SIZE,
        ], '', '&', PHP_QUERY_RFC3986);
        $found = [];
        $pages = 0;

        while ($url !== '' && $pages++ < self::MAX_PAGES) {
            $response = $this->http->send(new HttpRequest('GET', $url, self::headers($accessToken)));

            if ($response->status !== 200) {
                throw EbayApiException::fromResponse('Reading open sales', $response);
            }

            $page = $response->json();

            foreach ((array) ($page['orders'] ?? []) as $order) {
                if (($order['cancelStatus']['cancelState'] ?? 'NONE_REQUESTED') === 'CANCELED') {
                    continue;
                }

                $found[] = OpenShipment::fromOrder($order);
            }

            $url = (string) ($page['next'] ?? '');
        }

        return $found;
    }

    /**
     * The tracking numbers eBay holds for an order, comma-separated. One call per labelled order: the order search
     * does not carry them. An unreadable answer gives '' — the number is shown, not needed for anything.
     */
    private function tracking(string $accessToken, string $orderId): string
    {
        $response = $this->http->send(new HttpRequest(
            'GET',
            $this->environment->apiHost() . '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/shipping_fulfillment',
            self::headers($accessToken)
        ));

        if ($response->status !== 200) {
            return '';
        }

        $numbers = [];

        foreach ((array) ($response->json()['fulfillments'] ?? []) as $fulfillment) {
            $number = (string) ($fulfillment['shipmentTrackingNumber'] ?? '');

            if ($number !== '') {
                $numbers[] = $number;
            }
        }

        return implode(', ', array_unique($numbers));
    }

    /** @param list<array{line_item_id: string, quantity: int}> $items */
    public function markShipped(string $accessToken, string $orderId, array $items, string $carrier, string $trackingNumber, string $shippedAt): void
    {
        if (! isset(self::CARRIERS[$carrier])) {
            throw new EbayApiException('Unknown carrier ' . $carrier);
        }

        $body = (string) json_encode([
            'lineItems'           => array_map(static fn (array $item): array => ['lineItemId' => $item['line_item_id'], 'quantity' => $item['quantity']], $items),
            'shippedDate'         => $shippedAt,
            'shippingCarrierCode' => $carrier,
            'trackingNumber'      => $trackingNumber,
        ]);
        $response = $this->http->send(new HttpRequest(
            'POST',
            $this->environment->apiHost() . '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/shipping_fulfillment',
            self::headers($accessToken) + ['Content-Type' => 'application/json'],
            $body
        ));

        if ($response->status !== 201 && $response->status !== 200) {
            throw EbayApiException::fromResponse('Reporting the shipment of ' . $orderId, $response);
        }
    }

    /** @return array<string, string> */
    private static function headers(string $accessToken): array
    {
        return ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'];
    }
}
