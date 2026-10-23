<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\ShippingClient;

/**
 * The page «Shipping» (D-976): his eBay sales not shipped yet, read live from eBay on every view. Per sale: print
 * the insert for the parcel, report tracking number and carrier to eBay.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/verkauf.md §6
 */
final class ShippingScreen
{
    public const PAGE = 'taxebay-shipping';
    public const SHIP = 'taxebay_ship';
    public const INSERT = 'taxebay_insert';

    /**
     * @param list<OpenShipment>|string $open the sales, or why they could not be read
     * @param list<array{ok: bool, text: string}> $notices
     */
    public function page(array|string $open, array $notices): string
    {
        $html = '<div class="wrap"><h1>' . esc_html__('eBay shipping', 'taxebay') . '</h1>';

        foreach ($notices as $notice) {
            $html .= '<div class="notice ' . ($notice['ok'] ? 'notice-success' : 'notice-error') . ' inline"><p>' . esc_html($notice['text']) . '</p></div>';
        }

        if (Settings::needsReconnect()) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__('Reporting a shipment needs a new consent: press «Connect again» in the eBay tab.', 'taxebay')
                . ' <a href="' . esc_url(Plugin::settingsUrl()) . '">' . esc_html__('eBay tab', 'taxebay') . '</a></p></div>';
        }

        if (is_string($open)) {
            return $html . '<p>' . esc_html($open) . '</p></div>';
        }

        if ($open === []) {
            return $html . '<p>' . esc_html__('Nothing to ship.', 'taxebay') . '</p></div>';
        }

        foreach ($open as $sale) {
            $html .= $this->card($sale);
        }

        return $html . '</div>';
    }

    private function card(OpenShipment $sale): string
    {
        $items = '';

        foreach ($sale->items as $item) {
            $items .= '<li>' . esc_html($item['quantity'] . ' × ' . $item['title']) . '</li>';
        }

        $carriers = '';

        foreach (ShippingClient::CARRIERS as $code => $label) {
            $carriers .= '<option value="' . esc_attr($code) . '">' . esc_html($label) . '</option>';
        }

        $insert = wp_nonce_url(admin_url('admin-post.php?action=' . self::INSERT . '&order=' . rawurlencode($sale->orderId)), self::INSERT);

        return '<div class="postbox" style="padding:0 12px 12px;max-width:60em"><h2>' . esc_html($sale->orderId) . ' <small>· '
            . esc_html($sale->buyer) . ' · ' . esc_html($sale->orderedAt) . ' · '
            . esc_html($sale->paid ? __('paid', 'taxebay') : __('not paid yet', 'taxebay')) . '</small></h2>'
            . '<div style="display:flex;gap:24px;flex-wrap:wrap"><ul style="flex:1 1 20em">' . $items . '</ul>'
            . '<address style="flex:0 1 16em">' . implode('<br>', array_map('esc_html', $sale->address)) . '</address></div>'
            . '<p>' . esc_html(trim($sale->service . ' · ' . ($sale->total === null ? '' : $sale->total . ' ' . $sale->currency), ' ·')) . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr((string) wp_json_encode(
                /* translators: %s: eBay order number */
                sprintf(__('Report %s as shipped to eBay? The buyer is notified.', 'taxebay'), $sale->orderId)
            )) . ')">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::SHIP) . '"><input type="hidden" name="order" value="' . esc_attr($sale->orderId) . '">'
            . wp_nonce_field(self::SHIP, '_wpnonce', true, false)
            . '<a class="button" target="_blank" rel="noopener" href="' . esc_url($insert) . '">' . esc_html__('Print insert', 'taxebay') . '</a> '
            . '<select name="carrier" aria-label="' . esc_attr__('Carrier', 'taxebay') . '">' . $carriers . '</select> '
            . '<input type="text" name="tracking" class="regular-text" style="width:16em" required placeholder="' . esc_attr__('Tracking number', 'taxebay') . '"> '
            . get_submit_button(__('Mark shipped', 'taxebay'), 'primary', 'submit', false, $sale->paid ? null : ['disabled' => 'disabled'])
            . '</form></div>';
    }

    /**
     * The A6 note for the parcel: what is inside and where the documentation lives — the web addresses eBay does not
     * allow in the listing itself (concept §1). Until offers are built (step 2) it points to the site.
     */
    public function insert(OpenShipment $sale): string
    {
        $site = home_url('/');
        $qr = (new QRCode(new QROptions(['outputType' => 'svg', 'outputBase64' => true])))->render($site);
        $items = '';

        foreach ($sale->items as $item) {
            $items .= '<li>' . esc_html($item['quantity'] . ' × ' . $item['title']) . '</li>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($sale->orderId) . '</title>'
            . '<style>@page{size:A6;margin:8mm}body{font:11pt/1.4 sans-serif;margin:0}h1{font-size:13pt}img{width:30mm;float:right}</style></head>'
            . '<body onload="print()"><img src="' . esc_attr($qr) . '" alt=""><h1>' . esc_html__('Thank you for your purchase!', 'taxebay') . '</h1>'
            . '<ul>' . $items . '</ul><p>' . esc_html__('Documentation, sources and projects:', 'taxebay') . '<br><strong>' . esc_html($site) . '</strong></p>'
            . '<p><small>' . esc_html__('Private sale. If anything is wrong, write to me on eBay — we will find a solution.', 'taxebay') . '</small></p></body></html>';
    }
}
