<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpResponse;
use TaxEbay\Core\Sales\SaleMapper;
use TaxEbay\Core\Sales\SalesClient;

/** The fixture follows eBay's documented response, not a measured one. */
final class SalesTest extends TestCase
{
    private static function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/fulfillment-orders.json');
    }

    public function testReadsAllPagesAndShipmentOfShippedOrders(): void
    {
        $http = new ScriptedTransport([
            new HttpResponse(200, self::fixture()),
            new HttpResponse(200, '{"fulfillments":[{"shipmentTrackingNumber":"00340434","shippedDate":"2026-10-13T09:00:00.000Z"}]}'),
            new HttpResponse(200, '{"orders":[]}'),
        ]);

        $orders = (new SalesClient(Environment::Production, $http))->ordersModifiedSince('token', '2026-10-01T00:00:00.000Z');

        self::assertCount(1, $orders);
        self::assertSame('00340434', $orders[0]['_fulfillments'][0]['shipmentTrackingNumber']);
        self::assertStringContainsString('filter=lastmodifieddate%3A%5B2026-10-01T00%3A00%3A00.000Z..%5D', $http->sent[0]->url);
        self::assertStringEndsWith('/order/12-34567-89012/shipping_fulfillment', $http->sent[1]->url);
        self::assertStringEndsWith('offset=200', $http->sent[2]->url);
    }

    public function testMapsASaleToTheSharedProposal(): void
    {
        $order = json_decode(self::fixture(), true)['orders'][0];
        $order['_fulfillments'] = [['shipmentTrackingNumber' => '00340434', 'shippedDate' => '2026-10-13T09:00:00.000Z']];

        $proposal = (new SaleMapper())->map($order)->toArray();

        self::assertSame([
            'platform'      => 'eBay',
            'order_number'  => '12-34567-89012',
            'kind'          => 'sold',
            'order_date'    => '2026-10-12',
            'currency'      => 'EUR',
            'lines'         => [['title' => 'Greaseweazle V4.1 floppy adapter', 'quantity' => 3.0, 'unit_price' => '10.00', 'article_number' => '315000000001']],
            'shipping_cost' => '4.99',
            'total'         => '34.99',
            'counterparty'  => 'retro_fan_42',
            'fees'          => '0.00',
            'shipped_date'  => '2026-10-13',
            'tracking'      => ['00340434'],
            'source'        => 'ebay',
            'external_id'   => '12-34567-89012',
        ], $proposal);
        self::assertStringNotContainsString('Not To Be Stored', (string) json_encode($proposal));
    }

    public function testUnitPriceIsRoundedInCents(): void
    {
        $proposal = (new SaleMapper())->map(['orderId' => 'x', 'lineItems' => [['title' => 't', 'quantity' => 3, 'lineItemCost' => ['value' => '10.00']]]]);

        self::assertSame('3.33', $proposal->lines[0]->unitPrice);
    }

    public function testCancelledOrderIsRecognised(): void
    {
        self::assertTrue((new SaleMapper())->isCancelled(['cancelStatus' => ['cancelState' => 'CANCELED']]));
        self::assertFalse((new SaleMapper())->isCancelled(['cancelStatus' => ['cancelState' => 'NONE_REQUESTED']]));
    }
}
