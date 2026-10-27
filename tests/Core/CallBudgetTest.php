<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\CallBudget;
use TaxEbay\Core\Api\CallLedger;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\HttpRequest;
use TaxEbay\Core\Api\HttpResponse;

final class CallBudgetTest extends TestCase
{
    private function ledger(): CallLedger
    {
        return new class implements CallLedger {
            /** @var array<string, array<string, int>> */
            public array $days = [];

            public function counts(string $day): array
            {
                return $this->days[$day] ?? [];
            }

            public function store(string $day, array $counts): void
            {
                $this->days[$day] = $counts;
            }
        };
    }

    public function testCountsEachCallByApi(): void
    {
        $ledger = $this->ledger();
        $budget = new CallBudget(new ScriptedTransport([new HttpResponse(200, ''), new HttpResponse(200, '')]), $ledger, '2026-10-21');

        $budget->send(new HttpRequest('POST', 'https://api.ebay.com/ws/api.dll'));
        $budget->send(new HttpRequest('POST', 'https://api.ebay.com/identity/v1/oauth2/token'));

        self::assertSame(['trading' => 1, 'oauth' => 1], $ledger->counts('2026-10-21'));
    }

    public function testRefusesBeforeTheCapWithoutCallingEbay(): void
    {
        $ledger = $this->ledger();
        $ledger->store('2026-10-21', ['trading' => CallBudget::CAPS['trading']]);
        $http = new ScriptedTransport([]);

        try {
            (new CallBudget($http, $ledger, '2026-10-21'))->send(new HttpRequest('POST', 'https://api.ebay.com/ws/api.dll'));
            self::fail('no refusal');
        } catch (EbayApiException $refusal) {
            self::assertStringContainsString('Daily cap for the trading API', $refusal->getMessage());
        }

        self::assertSame([], $http->sent);
    }

    public function testANewDayStartsFromZero(): void
    {
        $ledger = $this->ledger();
        $ledger->store('2026-10-20', ['trading' => CallBudget::CAPS['trading']]);

        (new CallBudget(new ScriptedTransport([new HttpResponse(200, '')]), $ledger, '2026-10-21'))->send(new HttpRequest('POST', 'https://api.ebay.com/ws/api.dll'));

        self::assertSame(['trading' => 1], $ledger->counts('2026-10-21'));
    }

    public function testRateLimitAnswerIsAFailure(): void
    {
        $this->expectExceptionMessage('HTTP 429');
        (new CallBudget(new ScriptedTransport([new HttpResponse(429, '')]), $this->ledger(), 'd'))->send(new HttpRequest('GET', 'https://apiz.ebay.com/commerce/identity/v1/user/'));
    }

    public function testEveryHostTheModuleCallsHasABudget(): void
    {
        foreach ([
            'https://api.ebay.com/identity/v1/oauth2/token'                => 'oauth',
            'https://apiz.ebay.com/commerce/identity/v1/user/'             => 'identity',
            'https://api.sandbox.ebay.com/ws/api.dll'                      => 'trading',
            'https://api.ebay.com/sell/fulfillment/v1/order?limit=200'     => 'fulfillment',
            'https://api.ebay.com/post-order/v2/inquiry/search?order_id=1' => 'postorder',
        ] as $url => $family) {
            self::assertSame($family, CallBudget::family($url));
        }
    }
}
