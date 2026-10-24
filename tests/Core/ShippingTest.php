<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpResponse;
use TaxEbay\Core\Shipping\InsertText;
use TaxEbay\Core\Shipping\DhlLabelLink;
use TaxEbay\Core\Shipping\LabelMatch;
use TaxEbay\Core\Shipping\LabelText;
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\Parcels;
use TaxEbay\Core\Shipping\ParcelList;
use TaxEbay\Core\Shipping\ShippingClient;
use TaxEbay\Core\Shipping\Stages;

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
        $http = new ScriptedTransport([new HttpResponse(200, '{"orders":[]}'), new HttpResponse(200, (string) json_encode(['orders' => [$labelled, self::order('P', '2026-10-22')]])), new HttpResponse(200, '{"fulfillments":[{"shipmentTrackingNumber":"00340434"}]}')]);

        $client = new ShippingClient(Environment::Production, $http);
        $open = $client->tracked('token', $client->open('token', '2026-10-20T00:00:00.000Z'));

        self::assertCount(1, $open);
        self::assertTrue($open[0]->labelled);
        self::assertStringContainsString('orderfulfillmentstatus%3A%7BFULFILLED%7CIN_PROGRESS%7D', $http->sent[1]->url);
        self::assertSame('00340434', $open[0]->tracking);
        self::assertStringEndsWith('/order/L/shipping_fulfillment', $http->sent[2]->url);
    }

    public function testParcelsMoveThroughHisStages(): void
    {
        $now = (int) strtotime('2026-10-23T12:00:00Z');
        $fresh = self::order('F', '2026-10-22');
        $fresh['orderFulfillmentStatus'] = 'FULFILLED';
        $fresh['lastModifiedDate'] = '2026-10-22T10:00:00.000Z';
        $old = $fresh;
        $old['orderId'] = 'O';
        $old['lastModifiedDate'] = '2026-10-10T10:00:00.000Z';

        self::assertSame(Stages::OPEN, Stages::of([OpenShipment::fromOrder(self::order('N', '2026-10-01'))], [], $now));
        self::assertSame(Stages::OPEN, Stages::of([OpenShipment::fromOrder($fresh)], [], $now));
        self::assertNull(Stages::of([OpenShipment::fromOrder($old)], [], $now));
        self::assertSame(Stages::RECEIVED, Stages::of([OpenShipment::fromOrder($old)], ['O' => ['stage' => Stages::RECEIVED, 'at' => $now]], $now));
        self::assertSame(Stages::SHIPPED, Stages::next(Stages::PACKED));
        self::assertNull(Stages::next(Stages::RECEIVED));
        self::assertNull(Stages::previous(Stages::OPEN));
        self::assertSame(Stages::SHIPPED, Stages::afterCarrier(Stages::PACKED, 'shipped'));
        self::assertSame(Stages::RECEIVED, Stages::afterCarrier(Stages::SHIPPED, 'delivered'));
        self::assertNull(Stages::afterCarrier(Stages::RECEIVED, 'shipped'));
        self::assertNull(Stages::afterCarrier(Stages::OPEN, 'problem'));
    }

    public function testSearchFilterAndSortLikeAWordPressList(): void
    {
        $cheap = self::order('A', '2026-10-20');
        $cheap['pricingSummary']['total'] = ['value' => '2.90', 'currency' => 'EUR'];
        $dear = self::order('B', '2026-10-21');
        $dear['pricingSummary']['total'] = ['value' => '12.80', 'currency' => 'EUR'];
        $dear['orderFulfillmentStatus'] = 'FULFILLED';
        $parcels = [[OpenShipment::fromOrder($cheap)], [OpenShipment::fromOrder($dear)->withTracking('00340434')]];
        $ids = static fn (array $list): array => array_map(static fn (array $parcel): string => $parcel[0]->orderId, $list);

        self::assertSame(['A', 'B'], $ids((new ParcelList())->apply($parcels)));
        self::assertSame(['B', 'A'], $ids((new ParcelList('', '', '', 'total', true))->apply($parcels)));
        self::assertSame(['B'], $ids((new ParcelList('0340'))->apply($parcels)));
        self::assertSame(['B'], $ids((new ParcelList('', ParcelList::LABEL_EBAY))->apply($parcels)));
        self::assertSame(['A'], $ids((new ParcelList('', ParcelList::LABEL_OWN))->apply($parcels)));
        self::assertSame([], $ids((new ParcelList('nothing like it'))->apply($parcels)));
    }

    public function testOnlyDhlLabelLinksAreFetched(): void
    {
        self::assertTrue(DhlLabelLink::accepts('https://www.dhl.de/int-versenden/gw/rest/init?token=abc%3D&download=letter&pos=1'));
        self::assertFalse(DhlLabelLink::accepts('http://www.dhl.de/int-versenden/gw/rest/init?token=abc'));
        self::assertFalse(DhlLabelLink::accepts('https://www.dhl.de.evil.example/int-versenden/gw/rest/init?token=abc'));
        self::assertFalse(DhlLabelLink::accepts('https://www.dhl.de/int-versenden/gw/restweb/Invoice?token=abc'));
        self::assertFalse(DhlLabelLink::accepts('https://user@www.dhl.de/int-versenden/gw/rest/init?token=abc'));
        self::assertFalse(DhlLabelLink::accepts('https://www.dhl.de/int-versenden/gw/rest/init'));
    }

    public function testLabelTextFindsItsParcel(): void
    {
        $font = gzcompress("begincmap\n3 beginbfchar\n<0001> <0020>\n<0002> <004D>\n<0003> <00DC>\nendbfchar\nendcmap");
        $page = gzcompress("BT /F1 10 Tf [<00020003>] TJ 0 -12 Td (12345 ORT) Tj ET");
        $pdf = "%PDF-1.4\n1 0 obj << /Font << /F1 2 0 R >> >> endobj\n2 0 obj << /Type /Font /ToUnicode 3 0 R >> endobj\n"
            . "3 0 obj << /Filter /FlateDecode >>\nstream\n" . $font . "\nendstream\nendobj\n"
            . "4 0 obj << /Filter /FlateDecode >>\nstream\n" . $page . "\nendstream\nendobj\n";
        $lines = LabelText::of($pdf);
        $sale = static fn (array $address): array => [new OpenShipment('A', 'b', '2026-10-23', true, [], $address, '', null, 'EUR')];

        self::assertSame(['MÜ', '12345 ORT'], $lines);
        self::assertTrue(LabelMatch::fits($lines, $sale(['Mü', 'Weg 1', '12345 Ort', 'DE'])));
        self::assertFalse(LabelMatch::fits($lines, $sale(['Mü', 'Weg 1', '54321 Ort', 'DE'])));
        self::assertFalse(LabelMatch::fits($lines, $sale(['Max', 'Weg 1', '12345 Ort', 'DE'])));
        self::assertSame([], LabelText::of('not a pdf'));
    }

    public function testInsertSpeaksTheBuyersLanguage(): void
    {
        self::assertSame('Vielen Dank für deinen Kauf!', InsertText::for('at')['thanks']);
        self::assertSame('Thank you for your purchase!', InsertText::for('FR')['thanks']);
        self::assertSame('Viel Spaß damit!', InsertText::for('DE')['enjoy']);
        self::assertSame('Seller', InsertText::for('US')['seller']);
        self::assertSame('DE', OpenShipment::fromOrder(self::order('A', '2026-10-20'))->country);
    }
}
