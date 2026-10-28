<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core\Mail;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Mail\EbayMailParser;
use Taxmod\Core\Import\Mail\MimeParser;

/**
 * The mail reader, moved here from Taxmod Mail (D-1006). Each expectation is what the Python importer (legacy/ in
 * Taxmod Mail) read from the same synthetic mail.
 */
final class EbayMailParserTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function read(string $fixture): array
    {
        $parser = new EbayMailParser('0.0.1');
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.eml'));
        self::assertTrue($parser->recognises($mail), 'not recognised: ' . $fixture);

        return $parser->parse($mail)->toArray();
    }

    public function testEbayConfirmation(): void
    {
        $order = self::read('e1');

        self::assertSame(['Ebay', '27-13610-49402', 'ordered', '6.99', '51.99', 'r-o-r-c'], [$order['platform'], $order['order_number'], $order['kind'], $order['shipping_cost'], $order['total'], $order['counterparty']]);
        self::assertSame([['title' => 'ISA 8-bit Floppy Multi-I/O Controller Zilog', 'quantity' => 1.0, 'unit_price' => '45', 'article_number' => '123456789012']], $order['lines']);
    }

    public function testEbayShippingBringsTracking(): void
    {
        $order = self::read('e2');

        self::assertSame(['shipped', '27-13610-49402', ['00340434610899111']], [$order['kind'], $order['order_number'], $order['tracking']]);
    }

    public function testEbaySale(): void
    {
        $order = self::read('e3');

        self::assertSame(['sold', '11-22222-33333', '5.49', '35.49', 'retrofan42'], [$order['kind'], $order['order_number'], $order['shipping_cost'], $order['total'], $order['counterparty']]);
        self::assertSame([['title' => 'Commodore 64 Netzteil', 'quantity' => 2.0, 'unit_price' => '15', 'article_number' => null]], $order['lines']);
    }

    public function testEbayTwoItemsQuantityAndVat(): void
    {
        $order = self::read('e4');

        self::assertSame(['0', '18.45', '2.95'], [$order['shipping_cost'], $order['total'], $order['fees']]);
        self::assertSame([
            ['title' => 'Z80 CPU', 'quantity' => 3.0, 'unit_price' => '4', 'article_number' => '223344556677'],
            ['title' => '62256 SRAM', 'quantity' => 1.0, 'unit_price' => '3.5', 'article_number' => '998877665544'],
        ], $order['lines']);
    }

    public function testEbayAdvertIsNoReceipt(): void
    {
        self::assertSame('no_receipt', self::read('e5')['kind']);
    }

    public function testEbayMailAboutOwnSaleIsNoPurchase(): void
    {
        $order = self::read('e7');

        self::assertSame(['sold', '24-15238-03470', []], [$order['kind'], $order['order_number'], $order['lines']]);
    }

    public function testEbayInvoiceWithoutOrderNumberGetsThePythonKey(): void
    {
        $order = self::read('e6');

        self::assertSame(['invoice', 'ohne-Nr:334455667788', '24.8', true], [$order['kind'], $order['order_number'], $order['total'], $order['number_missing']]);
    }

    public function testEbayInvoiceQuantityFollowsLineTotal(): void
    {
        $order = self::read('e8');

        self::assertSame([1.0, '16'], [$order['lines'][0]['quantity'], $order['lines'][0]['unit_price']]);
    }

    public function testCancellationOfOwnSaleIsNoPurchase(): void
    {
        foreach (['e9', 'e10'] as $fixture) {
            $order = self::read($fixture);

            self::assertSame(['sold', '24-13651-27152', []], [$order['kind'], $order['order_number'], $order['lines']]);
        }
    }

    public function testEbayEnglishDeliveryMail(): void
    {
        $order = self::read('e11');

        self::assertSame(['09-15343-88398', 'delivered', 'suswl77'], [$order['order_number'], $order['kind'], $order['counterparty']]);
        self::assertSame([['title' => 'Creative Soundblaster 16 CT2940 ISA', 'quantity' => 1.0, 'unit_price' => null, 'article_number' => '237124172289']], $order['lines']);
    }

    public function testItNamesWhatItReads(): void
    {
        self::assertNotSame([], (new EbayMailParser('1.2.3'))->kinds());
        self::assertSame('1.2.3', (new EbayMailParser('1.2.3'))->version());
    }
}
