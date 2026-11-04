<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\TargetModel;
use Taxmod\WordPress\Import\ModelGateway;
use Taxmod\WordPress\Import\Targets;
use TaxEbay\Core\Sales\SaleMapper;
use WP_Error;

/**
 * The record «Verkauf» an eBay order was imported as (D-979), found by platform and order number through the modeller's
 * import targets: the Bezug of a resend's loss booking and where its new tracking number is kept (D-1168).
 */
final class SaleRecord
{
    private function __construct(private readonly TargetModel $sales, private readonly ModelGateway $gateway)
    {
    }

    /** null when the modeller has no sale targets set, or is too old for them. */
    public static function lookup(): ?self
    {
        if (! class_exists(Targets::class) || ! class_exists(ModelGateway::class) || ! ModelGateway::available()) {
            return null;
        }

        $sales = Targets::load()->forSales();

        return $sales === null || ! $sales->hasOrderField('tracking') ? null : new self($sales, new ModelGateway($sales));
    }

    public function find(string $orderId): ?ExistingOrder
    {
        $found = $this->gateway->findOrder(SaleMapper::PLATFORM, $orderId);

        return $found instanceof ExistingOrder ? $found : null;
    }

    /** Adds the new parcel's number next to the first one; the first stays, so both labels are on record. */
    public function addTracking(string $orderId, string $tracking): true|WP_Error
    {
        $sale = $this->find($orderId);

        if ($sale === null || $tracking === '') {
            return true;
        }

        $changes = (new ChangeListBuilder($this->sales))->shipped($sale, [$tracking]);
        $written = $changes === [] ? [] : $this->gateway->write($changes);

        return $written instanceof WP_Error ? $written : true;
    }

    /** Taking a resend back takes its number off the sale again. */
    public function removeTracking(string $orderId, string $tracking): true|WP_Error
    {
        $sale = $this->find($orderId);

        if ($sale === null || $tracking === '' || ! in_array($tracking, $sale->trackingNumbers, true)) {
            return true;
        }

        $written = $this->gateway->write([['op' => 'set_values', 'record' => (string) $sale->recordId, 'values' => [
            (string) $this->sales->orderField('tracking') => ['values' => array_values(array_diff($sale->trackingNumbers, [$tracking]))],
        ]]]);

        return $written instanceof WP_Error ? $written : true;
    }
}
