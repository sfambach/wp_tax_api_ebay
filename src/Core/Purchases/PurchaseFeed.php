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
        $cursor = $now;

        foreach ($this->client->ordersCreatedBetween($accessToken, $from, $now) as $order) {
            if ($this->mapper->isCancelled($order)) {
                continue;
            }

            // Not paid yet, so no real number: read again from its creation until eBay gives one (2026-10-28).
            if ($this->mapper->awaitsNumber($order)) {
                $created = new DateTimeImmutable((string) $order->CreatedTime);
                $cursor = min($cursor, $created->modify('+1 day'));

                continue;
            }

            $proposal = $this->mapper->map($order);
            $proposals[] = new IncomingProposal($proposal, [
                'seller'       => $proposal->counterparty,
                'items'        => count($proposal->lines),
                'order_status' => (string) $order->OrderStatus,
            ]);
        }

        // The next run starts a day before the cursor (OVERLAP), so a waiting order is read again.
        return new FetchBatch($proposals, $cursor->format(DATE_ATOM));
    }
}
