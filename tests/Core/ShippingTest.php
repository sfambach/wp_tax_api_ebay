<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpResponse;
use TaxEbay\Core\Shipping\InsertText;
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\Parcels;
use TaxEbay\Core\Shipping\ShippingClient;

/** The order follows eBay's documented Fulfillment answer, not a measured one. */
final class ShippingTest extends TestCase
{
    private static function order(string $id, string $created, string $cancel = 'NONE_REQUESTED'): array
    {
        return [
            'orderId' => $id, 'creationDate' => $created . 'T10:00:00.000Z', 'orderPaymentStatus' => 'PAID',
            'buyer' => ['username' => 'retro-fan'], 'cancelStatus' => ['cancelState' => $cancel],
            'pricingSummary' => ['total' => ['value' => '24.90', 'currency' => 'EUR']],
            'lineItems' => [['lineItemId' => '10001', 'title' => 'ISA 8-Bit Ethernet PCB', 'quantity' => 2, 'sku' => 'taxmod-1']],
            'fulfillmentStartInstructions' => [['shippingStep' => ['shippingServiceCode' => 'DE_DHLPaket', 'shipTo' => [
                'fullName' => 'Erika Muster', 'contactAddress' => ['addressLine1' => 'Weg 1', 'postalCode' => '12345', 'city' => 'Stadt', 'countryCode' => 'DE'],
            ]]]],
        ];
    }

    public function testListsOpenSalesOldestFirstWithoutCancelled(): void
    {
        $page = (string) json_encode(['orders' => [self::order('B', '2026-10-22'), self::order('A', '2026-10-20'), self::order('C', '2026-10-21', 'CANCELED')]]);
        $http = new ScriptedTransport([new HttpResponse(200, $page)]);

        $open = (new ShippingClient(Environment::Production, $http))->open('token');

        self::assertSame(['A', 'B'], array_map(static fn ($sale): string => $sale->orderId, $open));
        self::assertStringContainsString('orderfulfillmentstatus%3A%7BNOT_STARTED%7CIN_PROGRESS%7D', $http->sent[0]->url);
        self::assertSame(['Erika Muster', 'Weg 1', '12345 Stadt', 'DE'], $open[0]->address);
        self::assertSame(2, $open[0]->items[0]['quantity']);
        self::assertTrue($open[0]->paid);
    }

    public function testReportsTheShipmentWithAllItems(): void
    {
        $http = new ScriptedTransport([new HttpResponse(201, '')]);
        $items = [['line_item_id' => '10001', 'title' => 'x', 'quantity' => 2, 'sku' => '']];

        (new ShippingClient(Environment::Production, $http))->markShipped('token', 'A', $items, 'DHL', '00340434', '2026-10-23T08:00:00.000Z');

        self::assertSame('POST', $http->sent[0]->method);
        self::assertStringEndsWith('/sell/fulfillment/v1/order/A/shipping_fulfillment', $http->sent[0]->url);
        self::assertSame([
            'lineItems' => [['lineItemId' => '10001', 'quantity' => 2]],
            'shippedDate' => '2026-10-23T08:00:00.000Z', 'shippingCarrierCode' => 'DHL', 'trackingNumber' => '00340434',
        ], json_decode($http->sent[0]->body, true));
    }

    public function testRefusesAnUnknownCarrier(): void
    {
        $this->expectException(EbayApiException::class);
        (new ShippingClient(Environment::Production, new ScriptedTransport([])))->markShipped('t', 'A', [], 'POSTKUTSCHE', '1', '2026-10-23T08:00:00.000Z');
    }

    public function testSalesToTheSameBuyerAndAddressMakeOneParcel(): void
    {
        $a = OpenShipment::fromOrder(self::order('A', '2026-10-20'));
        $b = OpenShipment::fromOrder(['address' => null] + self::order('B', '2026-10-21'));
        $other = self::order('C', '2026-10-21');
        $other['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['addressLine1'] = 'Gasse 2';
        $c = OpenShipment::fromOrder($other);

        $parcels = Parcels::of([$a, $b, $c]);

        self::assertSame([['A', 'B'], ['C']], array_map(static fn (array $parcel): array => array_map(static fn ($sale): string => $sale->orderId, $parcel), $parcels));
    }

    public function testLabelsBoughtAtEbayStillShowForPacking(): void
    {
        $labelled = self::order('L', '2026-10-22');
        $labelled['orderFulfillmentStatus'] = 'FULFILLED';
        $http = new ScriptedTransport([new HttpResponse(200, '{"orders":[]}'), new HttpResponse(200, (string) json_encode(['orders' => [$labelled]]))]);

        $open = (new ShippingClient(Environment::Production, $http))->open('token', '2026-10-20T00:00:00.000Z');

        self::assertCount(1, $open);
        self::assertTrue($open[0]->labelled);
        self::assertStringContainsString('orderfulfillmentstatus%3A%7BFULFILLED%7D', $http->sent[1]->url);
    }

    public function testInsertSpeaksTheBuyersLanguage(): void
    {
        self::assertSame('Vielen Dank für deinen Kauf!', InsertText::for('at')['thanks']);
        self::assertSame('Thank you for your purchase!', InsertText::for('FR')['thanks']);
        self::assertSame('DE', OpenShipment::fromOrder(self::order('A', '2026-10-20'))->country);
    }
}
