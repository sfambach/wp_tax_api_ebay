<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use TaxEbay\Core\Shipping\InsertText;
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\Parcels;
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
    public function page(array|string $open, array $notices, string $seller): string
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

        foreach (Parcels::of($open) as $parcel) {
            $html .= $this->card($parcel, $seller);
        }

        return $html . '</div>';
    }

    /** @param non-empty-list<OpenShipment> $parcel sales to one buyer at one address — one parcel */
    private function card(array $parcel, string $seller): string
    {
        $first = $parcel[0];
        $ids = array_map(static fn (OpenShipment $sale): string => $sale->orderId, $parcel);
        $paid = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->paid, true);
        $labelled = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->labelled, true);
        $items = '';
        $lines = [];

        foreach ($parcel as $sale) {
            foreach ($sale->items as $item) {
                $items .= '<li>' . esc_html($item['quantity'] . ' × ' . $item['title']) . (count($parcel) > 1 ? ' <small>(' . esc_html($sale->orderId) . ')</small>' : '') . '</li>';
            }

            $lines[] = trim($sale->service . ' · ' . ($sale->total === null ? '' : $sale->total . ' ' . $sale->currency), ' ·');
        }

        $carriers = '';

        foreach (ShippingClient::CARRIERS as $code => $label) {
            $carriers .= '<option value="' . esc_attr($code) . '">' . esc_html($label) . '</option>';
        }

        $insert = wp_nonce_url(admin_url('admin-post.php?action=' . self::INSERT . '&orders=' . rawurlencode(implode(',', $ids))), self::INSERT);
        $heading = count($parcel) > 1
            /* translators: %d: number of sales */
            ? sprintf(__('%d sales, one parcel', 'taxebay'), count($parcel))
            : $first->orderId;

        return '<div class="postbox" style="padding:0 12px 12px;max-width:60em"><h2>' . esc_html($heading) . ' <small>· '
            . esc_html($first->buyer) . ' · ' . esc_html($first->orderedAt) . ' · '
            . esc_html($paid ? __('paid', 'taxebay') : __('not all paid yet', 'taxebay')) . '</small></h2>'
            . '<div style="display:flex;gap:24px;flex-wrap:wrap"><ul style="flex:1 1 20em">' . $items . '</ul>'
            . '<address style="flex:0 1 16em">' . implode('<br>', array_map('esc_html', $first->address)) . '</address></div>'
            . '<p>' . esc_html(implode(' | ', $lines)) . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr((string) wp_json_encode(
                /* translators: %s: eBay order numbers */
                sprintf(__('Report %s as shipped to eBay? The buyer is notified.', 'taxebay'), implode(', ', $ids))
            )) . ')">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::SHIP) . '"><input type="hidden" name="orders" value="' . esc_attr(implode(',', $ids)) . '">'
            . wp_nonce_field(self::SHIP, '_wpnonce', true, false)
            . '<a class="button" target="_blank" rel="noopener" href="' . esc_url($insert) . '">' . esc_html__('Print insert', 'taxebay') . '</a> '
            . '<label class="button">' . esc_html__('Label PDF/image → 62 mm', 'taxebay')
            . '<input type="file" accept="application/pdf,image/*" hidden data-taxebay-label="' . esc_attr(self::reference($ids, $seller)) . '"></label> '
            . '<button type="button" class="button" data-taxebay-paste="' . esc_attr(self::reference($ids, $seller)) . '">' . esc_html__('Paste screenshot → 62 mm', 'taxebay') . '</button> '
            . ($labelled
                ? '<span class="description">' . esc_html__('Label bought at eBay — eBay already counts it as shipped. Pack it and add the insert.', 'taxebay') . '</span>'
                : '<select name="carrier" aria-label="' . esc_attr__('Carrier', 'taxebay') . '">' . $carriers . '</select> '
                . '<input type="text" name="tracking" class="regular-text" style="width:16em" required placeholder="' . esc_attr__('Tracking number', 'taxebay') . '"> '
                . get_submit_button(__('Mark shipped', 'taxebay'), 'primary', 'submit', false, $paid ? null : ['disabled' => 'disabled']))
            . '</form></div>';
    }

    /**
     * The note for the parcel: what is inside and where the documentation lives — the web addresses eBay does not allow
     * in the listing itself (concept §1). Until offers are built (step 2) it points to the site. Two sizes: A6 for the
     * office printer, 62 mm endless tape for his Brother QL-810W.
     *
     * @param non-empty-list<OpenShipment> $parcel
     */
    public function insert(array $parcel, string $format, string $seller): string
    {
        $ids = array_map(static fn (OpenShipment $sale): string => $sale->orderId, $parcel);
        $site = home_url('/');
        $qr = (new QRCode(new QROptions(['outputType' => 'svg', 'outputBase64' => true])))->render($site);
        $items = '';

        foreach ($parcel as $sale) {
            foreach ($sale->items as $item) {
                $items .= '<li>' . esc_html($item['quantity'] . ' × ' . $item['title']) . '</li>';
            }
        }

        $words = InsertText::for($parcel[0]->country);
        $page = $format === Settings::INSERT_LABEL
            ? '@page{size:62mm 100mm;margin:3mm}body{font:8pt/1.3 sans-serif}h1{font-size:9.5pt}img{width:22mm}'
            : '@page{size:A6;margin:8mm}body{font:11pt/1.4 sans-serif}h1{font-size:13pt}img{width:30mm}';

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($parcel[0]->orderId) . '</title>'
            // His signature in handwriting (his wish, 2026-10-23); without the web font the browser's own cursive.
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&display=block">'
            . '<style>' . $page . 'body{margin:0}ul{padding-left:1.2em}img{float:right}.sign{font-family:"Dancing Script",cursive;font-size:1.6em;margin:.4em 0 0}</style></head>'
            . '<body onload="document.fonts.ready.then(function(){print()})"><img src="' . esc_attr($qr) . '" alt=""><h1>' . esc_html($words['thanks']) . '</h1>'
            . '<ul>' . $items . '</ul><p>' . esc_html($words['docs']) . '<br><strong>' . esc_html($site) . '</strong></p>'
            . '<p><small>' . esc_html($words['private']) . '<br>' . esc_html(self::reference($ids, $seller, $words)) . '</small></p>'
            . '<p class="sign">' . esc_html($words['enjoy']) . '<br>SFA</p></body></html>';
    }

    /**
     * «Order … · Seller …» — under the label and on the insert, so parcel, label and eBay order can be matched.
     *
     * @param list<string> $ids
     * @param array{order: string, seller: string}|null $words the buyer's language on the insert; his own on the label
     */
    private static function reference(array $ids, string $seller, ?array $words = null): string
    {
        $order = $words['order'] ?? __('Order', 'taxebay');
        $by = $words['seller'] ?? __('Seller', 'taxebay');

        return $order . ' ' . implode(', ', $ids) . ($seller === '' ? '' : ' · ' . $by . ' ' . $seller);
    }
}
