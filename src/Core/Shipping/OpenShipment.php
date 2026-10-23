<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * One sale he still has to pack and send, as the shipping list shows it. The address lives only in this object for
 * the page view; nothing of it is stored (concept §6).
 */
final class OpenShipment
{
    /**
     * @param list<array{line_item_id: string, title: string, quantity: int, sku: string}> $items
     * @param list<string> $address lines as eBay gives them, name first
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $buyer,
        public readonly string $orderedAt,
        public readonly bool $paid,
        public readonly array $items,
        public readonly array $address,
        public readonly string $service,
        public readonly ?string $total,
        public readonly string $currency,
        public readonly bool $labelled = false,
    ) {
    }

    /** @param array<string, mixed> $order a Fulfillment API order; `mixed` because eBay's JSON nests */
    public static function fromOrder(array $order): self
    {
        // eBay marks an order shipped as soon as he buys the label there; it still has to be packed.
        $labelled = ($order['orderFulfillmentStatus'] ?? '') === 'FULFILLED';
        $items = [];

        foreach ((array) ($order['lineItems'] ?? []) as $line) {
            $items[] = [
                'line_item_id' => (string) ($line['lineItemId'] ?? ''),
                'title'        => (string) ($line['title'] ?? ''),
                'quantity'     => (int) ($line['quantity'] ?? 1),
                'sku'          => (string) ($line['sku'] ?? ''),
            ];
        }

        $step = (array) ($order['fulfillmentStartInstructions'][0]['shippingStep'] ?? []);
        $to = (array) ($step['shipTo'] ?? []);
        $place = (array) ($to['contactAddress'] ?? []);
        $address = array_values(array_filter(array_map('strval', [
            $to['fullName'] ?? '',
            $place['addressLine1'] ?? '',
            $place['addressLine2'] ?? '',
            trim(($place['postalCode'] ?? '') . ' ' . ($place['city'] ?? '')),
            $place['countryCode'] ?? '',
        ]), static fn (string $line): bool => trim($line) !== ''));
        $total = $order['pricingSummary']['total'] ?? [];

        return new self(
            (string) ($order['orderId'] ?? ''),
            (string) ($order['buyer']['username'] ?? ''),
            substr((string) ($order['creationDate'] ?? ''), 0, 10),
            ($order['orderPaymentStatus'] ?? '') === 'PAID',
            $items,
            $address,
            (string) ($step['shippingServiceCode'] ?? ''),
            isset($total['value']) ? (string) $total['value'] : null,
            (string) ($total['currency'] ?? 'EUR'),
            $labelled,
        );
    }
}
