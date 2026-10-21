<?php declare(strict_types=1);

namespace TaxEbay\Core\Purchases;

use DateTimeImmutable;
use Taxmod\Core\Import\FetchBatch;
use Taxmod\Core\Import\IncomingProposal;

/**
 * One run of the eBay source for the modeller's inbox (D-950, D-953): his purchases since the last run, as proposals
 * with the eBay order id as stable external id. The cursor is the start of the previous run; a day of overlap catches
 * orders eBay files late, and the inbox drops what it already holds.
 */
final class PurchaseFeed
{
    private const OVERLAP = '-1 day';

    public function __construct(
        private readonly PurchasesClient $client,
        private readonly PurchaseMapper $mapper = new PurchaseMapper(),
    ) {
    }

    public function run(string $accessToken, ?string $cursor, DateTimeImmutable $now): FetchBatch
    {
        $oldest = $now->modify('-' . (PurchasesClient::MAX_DAYS_BACK - 1) . ' days');
        $from = $cursor === null ? $oldest : max($oldest, (new DateTimeImmutable($cursor))->modify(self::OVERLAP));
        $proposals = [];

        foreach ($this->client->ordersCreatedBetween($accessToken, $from, $now) as $order) {
            if ($this->mapper->isCancelled($order)) {
                continue;
            }

            $proposal = $this->mapper->map($order);
            $proposals[] = new IncomingProposal($proposal, [
                'seller'       => $proposal->counterparty,
                'items'        => count($proposal->lines),
                'order_status' => (string) $order->OrderStatus,
            ]);
        }

        return new FetchBatch($proposals, $now->format(DATE_ATOM));
    }
}
