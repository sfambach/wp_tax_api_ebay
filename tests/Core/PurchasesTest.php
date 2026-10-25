<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpResponse;
use TaxEbay\Core\Purchases\PurchaseFeed;
use TaxEbay\Core\Purchases\PurchaseMapper;
use TaxEbay\Core\Purchases\PurchasesClient;
use TaxEbay\Core\Purchases\Reconciliation;

/** The fixture follows eBay's documented GetOrders answer, not a measured one. */
final class PurchasesTest extends TestCase
{
    private const EMPTY_LAST_PAGE = '<?xml version="1.0"?><GetOrdersResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Success</Ack><HasMoreOrders>false</HasMoreOrders><OrderArray/></GetOrdersResponse>';

    private static function page1(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/getorders-buyer-page1.xml');
    }

    public function testReadsPagesAsBuyerWithTheOauthToken(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, self::page1()), new HttpResponse(200, self::EMPTY_LAST_PAGE)]);

        $orders = (new PurchasesClient(Environment::Production, $http))
            ->ordersCreatedBetween('token', new DateTimeImmutable('2026-07-23T00:00:00Z'), new DateTimeImmutable('2026-10-21T00:00:00Z'));

        self::assertCount(1, $orders);
        self::assertCount(2, $http->sent);
        self::assertSame('https://api.ebay.com/ws/api.dll', $http->sent[0]->url);
        self::assertSame('GetOrders', $http->sent[0]->headers['X-EBAY-API-CALL-NAME']);
        self::assertSame('token', $http->sent[0]->headers['X-EBAY-API-IAF-TOKEN']);
        self::assertStringContainsString('<OrderRole>Buyer</OrderRole>', $http->sent[0]->body);
        self::assertStringContainsString('<CreateTimeFrom>2026-07-23T00:00:00.000Z</CreateTimeFrom>', $http->sent[0]->body);
        self::assertStringContainsString('<PageNumber>2</PageNumber>', $http->sent[1]->body);
    }

    public function testFailureCarriesEbaysMessage(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, '<?xml version="1.0"?><GetOrdersResponse xmlns="urn:ebay:apis:eBLBaseComponents"><Ack>Failure</Ack><Errors><LongMessage>Invalid token.</LongMessage></Errors></GetOrdersResponse>')]);

        $this->expectException(EbayApiException::class);
        $this->expectExceptionMessage('Invalid token.');
        (new PurchasesClient(Environment::Production, $http))->ordersCreatedBetween('t', new DateTimeImmutable(), new DateTimeImmutable());
    }

    public function testMapsAPurchaseToTheSharedProposal(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, self::page1()), new HttpResponse(200, self::EMPTY_LAST_PAGE)]);
        $order = (new PurchasesClient(Environment::Production, $http))->ordersCreatedBetween('t', new DateTimeImmutable(), new DateTimeImmutable())[0];
        $mapper = new PurchaseMapper();

        self::assertSame([
            'platform'      => 'eBay',
            'order_number'  => '05-12345-67890',
            'kind'          => 'ordered',
            'order_date'    => '2026-09-30',
            'currency'      => 'EUR',
            'lines'         => [['title' => '10x NE555 Timer DIP-8', 'quantity' => 2.0, 'unit_price' => '3.90', 'article_number' => '126000000001']],
            'shipping_cost' => '2.50',
            'total'         => '10.30',
            'counterparty'  => 'chip-haendler',
            'fees'          => null,
            'shipped_date'  => '2026-10-01',
            'tracking'      => ['JJD000390007'],
            'source'        => 'ebay',
            'external_id'   => '05-12345-67890',
            'number_missing' => false,
        ], $mapper->map($order)->toArray());
        self::assertSame(['05-12345-67890', '05-12345-67890!5000000000001'], $mapper->orderNumbers($order));
        self::assertFalse($mapper->isCancelled($order));
    }

    /** Measured 2026-10-21: three listings at 66.00 listed shipping, combined by the seller; he paid 132.00. */
    public function testCombinedShippingIsTheTotalLessTheItems(): void
    {
        $item = static fn (string $price): string => '<Transaction><QuantityPurchased>1</QuantityPurchased><TransactionPrice currencyID="EUR">' . $price . '</TransactionPrice><Item><ItemID>1</ItemID><Title>Board</Title></Item></Transaction>';
        $order = new \SimpleXMLElement('<Order><OrderID>05-15105-57121</OrderID><CreatedTime>2026-08-29T13:10:00.000Z</CreatedTime>'
            . '<ShippingServiceSelected><ShippingServiceCost currencyID="EUR">66.00</ShippingServiceCost></ShippingServiceSelected>'
            . '<Total currencyID="EUR">132.00</Total><TransactionArray>' . $item('50.00') . $item('30.00') . $item('30.00') . '</TransactionArray></Order>');

        $proposal = (new PurchaseMapper())->map($order);

        self::assertSame('22.00', $proposal->shippingCost);
        self::assertSame('132.00', $proposal->total);
    }

    public function testReconciliationMatchesAnyNumberIgnoringCaseAndSpaces(): void
    {
        $reconciliation = new Reconciliation([' 05-12345-67890 ', 'AB-1']);

        self::assertTrue($reconciliation->isKnown(['x', '05-12345-67890']));
        self::assertTrue($reconciliation->isKnown(['ab-1']));
        self::assertFalse($reconciliation->isKnown(['05-99999-00000']));
    }

    public function testFeedHandsStableIdsAndTheRunStartAsCursor(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, self::page1()), new HttpResponse(200, self::EMPTY_LAST_PAGE)]);
        $now = new DateTimeImmutable('2026-10-21T12:00:00+00:00');

        $batch = (new PurchaseFeed(new PurchasesClient(Environment::Production, $http)))->run('t', '2026-10-20T12:00:00+00:00', $now);

        self::assertSame('2026-10-21T12:00:00+00:00', $batch->cursor);
        self::assertCount(1, $batch->proposals);
        self::assertSame('05-12345-67890', $batch->proposals[0]->proposal->externalId);
        self::assertSame(['seller' => 'chip-haendler', 'items' => 1, 'order_status' => 'Completed'], $batch->proposals[0]->evidence);
        self::assertStringContainsString('<CreateTimeFrom>2026-10-19T12:00:00.000Z</CreateTimeFrom>', $http->sent[0]->body);
    }

    public function testFirstRunReachesBackAsFarAsEbayAnswers(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, self::EMPTY_LAST_PAGE)]);

        (new PurchaseFeed(new PurchasesClient(Environment::Production, $http)))->run('t', null, new DateTimeImmutable('2026-10-21T12:00:00Z'));

        self::assertStringContainsString('<CreateTimeFrom>2026-07-24T12:00:00.000Z</CreateTimeFrom>', $http->sent[0]->body);
    }
}
