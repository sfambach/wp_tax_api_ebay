<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpResponse;
use TaxEbay\Core\Shipping\Resends;
use TaxEbay\Core\Shipping\ShippingClient;
use TaxEbay\Core\Shipping\Stages;

/** D-1168: a parcel sent a second time — his note, its new number, and taking it back. */
final class ResendsTest extends TestCase
{
    /** @return array{at: int, reason: string, note: string, tracking: string, previous_tracking: string, previous_stage: string, deal: int, who: string} */
    private static function entry(int $at, string $tracking = '', int $deal = 0): array
    {
        return ['at' => $at, 'reason' => Resends::LOST, 'note' => '', 'tracking' => $tracking, 'previous_tracking' => 'A005EFF1A1000000040B', 'previous_stage' => Stages::SHIPPED, 'deal' => $deal, 'who' => 'Stefan'];
    }

    public function testAResendKeepsTheFirstNumberAndTheNewOne(): void
    {
        $stored = Resends::add([], ['24-15238-03470'], self::entry(100, 'a005 eff1-a100 0000 0472'));

        self::assertSame('A005EFF1A10000000472', Resends::trackingOf($stored, ['24-15238-03470']));
        self::assertSame('A005EFF1A1000000040B', Resends::latest($stored, ['24-15238-03470'])['previous_tracking']);
        self::assertSame(1, Resends::count($stored, ['24-15238-03470']));
    }

    public function testTheNumberCanFollowAndAnUnknownReasonIsOther(): void
    {
        $entry = self::entry(100);
        $entry['reason'] = 'gone';
        $stored = Resends::add([], ['A', 'B'], $entry);

        self::assertSame('', Resends::trackingOf($stored, ['A', 'B']));
        self::assertSame(Resends::OTHER, Resends::latest($stored, ['B'])['reason']);

        $stored = Resends::withTracking($stored, ['A', 'B'], '00340434');

        self::assertSame('00340434', Resends::trackingOf($stored, ['B']));
    }

    public function testTakingBackRemovesOnlyTheNewestAndHandsItOver(): void
    {
        $stored = Resends::add(Resends::add([], ['A'], self::entry(100, 'X1', 7)), ['A'], self::entry(200, 'X2', 8));
        [$kept, $taken] = Resends::undo($stored, ['A']);

        self::assertSame(8, $taken['deal']);
        self::assertSame('X1', Resends::trackingOf($kept, ['A']));

        [$kept, $taken] = Resends::undo($kept, ['A']);

        self::assertSame(7, $taken['deal']);
        self::assertSame([], $kept);
        self::assertNull(Resends::undo($kept, ['A'])[1]);
    }

    public function testOldNotesFallAway(): void
    {
        $now = 1_000 * 86400;
        $stored = Resends::add(Resends::add([], ['old'], self::entry($now - (Resends::KEEP_DAYS + 1) * 86400)), ['new'], self::entry($now - 86400));

        self::assertSame(['new'], array_keys(Resends::kept($stored, $now)));
    }

    public function testResentOrdersAreReadByNumberWhenTheWindowMissesThem(): void
    {
        $order = ['orderId' => 'R', 'creationDate' => '2026-09-01T10:00:00.000Z', 'orderFulfillmentStatus' => 'FULFILLED', 'lineItems' => []];
        $http = new ScriptedTransport([
            new HttpResponse(200, '{"orders":[]}'),
            new HttpResponse(200, '{"orders":[]}'),
            new HttpResponse(200, (string) json_encode(['orders' => [$order]])),
        ]);

        $open = (new ShippingClient(Environment::Production, $http))->open('token', '2026-10-05T00:00:00.000Z', ['R']);

        self::assertSame(['R'], array_map(static fn ($sale): string => $sale->orderId, $open));
        self::assertTrue($open[0]->labelled);
        self::assertStringContainsString('orderIds=R', $http->sent[2]->url);
    }

    public function testNoExtraCallWhenTheResentOrderIsAlreadyThere(): void
    {
        $order = ['orderId' => 'R', 'creationDate' => '2026-10-20T10:00:00.000Z', 'orderFulfillmentStatus' => 'FULFILLED', 'lineItems' => []];
        $http = new ScriptedTransport([new HttpResponse(200, '{"orders":[]}'), new HttpResponse(200, (string) json_encode(['orders' => [$order]]))]);

        (new ShippingClient(Environment::Production, $http))->open('token', '2026-10-05T00:00:00.000Z', ['R']);

        self::assertCount(2, $http->sent);
    }
}
