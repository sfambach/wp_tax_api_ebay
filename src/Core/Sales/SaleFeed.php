<?php declare(strict_types=1);

namespace TaxEbay\Core\Sales;

use DateTimeImmutable;
use Taxmod\Core\Import\IncomingProposal;

/**
 * His sales since the last run as proposals for the modeller's inbox (D-979): orders eBay changed since the cursor,
 * so a later shipment brings the sale again and the inbox fills the tracking number in (D-964).
 */
final class SaleFeed
{
    private const OVERLAP = '-1 day';
    private const MAX_DAYS_BACK = 90;

    public function __construct(
        private readonly SalesClient $client,
        private readonly SaleMapper $mapper = new SaleMapper(),
    ) {
    }

    /** @return list<IncomingProposal> */
    public function run(string $accessToken, ?string $cursor, DateTimeImmutable $now): array
    {
        $oldest = $now->modify('-' . (self::MAX_DAYS_BACK - 1) . ' days');
        $from = $cursor === null ? $oldest : max($oldest, (new DateTimeImmutable($cursor))->modify(self::OVERLAP));
        $proposals = [];

        foreach ($this->client->ordersModifiedSince($accessToken, $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z')) as $order) {
            if ($this->mapper->isCancelled($order)) {
                continue;
            }

            $proposal = $this->mapper->map($order);
            $proposals[] = new IncomingProposal($proposal, [
                'buyer'        => $proposal->counterparty,
                'items'        => count($proposal->lines),
                'order_status' => (string) ($order['orderFulfillmentStatus'] ?? ''),
            ]);
        }

        return $proposals;
    }
}
