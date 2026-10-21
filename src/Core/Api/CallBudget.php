<?php declare(strict_types=1);

namespace TaxEbay\Core\Api;

/**
 * Counts every call per eBay API and refuses one before it would pass a daily cap — a bug, a loop or a busy button
 * must never run into eBay's call limits (his request 2026-10-21, with eBay's table of default limits).
 *
 * The caps are a tenth of eBay's default daily limits, the day is eBay's: UTC. A normal run needs two to four calls.
 */
final class CallBudget implements HttpTransport
{
    /** API family => own cap per day; eBay's default limit in the comment. */
    public const CAPS = [
        'oauth'       => 500,    // token endpoint: not in eBay's table, kept low
        'identity'    => 500,    // Identity API: 5,000
        'trading'     => 500,    // Trading API: 5,000
        'fulfillment' => 10_000, // Fulfillment API, order resource: 100,000
        'finances'    => 1_500,  // Finances API: 15,000
    ];

    public function __construct(
        private readonly HttpTransport $inner,
        private readonly CallLedger $ledger,
        private readonly string $day,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $family = self::family($request->url);
        $counts = $this->ledger->counts($this->day);
        $used = $counts[$family] ?? 0;

        if ($used >= self::CAPS[$family]) {
            throw new EbayApiException(sprintf('Daily cap for the %s API reached (%d calls); next try after midnight UTC', $family, $used));
        }

        $counts[$family] = $used + 1;
        $this->ledger->store($this->day, $counts);
        $response = $this->inner->send($request);

        if ($response->status === 429) {
            throw new EbayApiException(sprintf('eBay refuses more %s calls for now (HTTP 429)', $family));
        }

        return $response;
    }

    public static function family(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return match (true) {
            str_contains($path, '/identity/v1/oauth2/') => 'oauth',
            str_contains($path, '/commerce/identity/')  => 'identity',
            str_contains($path, '/ws/api.dll')          => 'trading',
            str_contains($path, '/sell/fulfillment/')   => 'fulfillment',
            str_contains($path, '/sell/finances/')      => 'finances',
            default => throw new EbayApiException('No call budget for ' . $path),
        };
    }
}
