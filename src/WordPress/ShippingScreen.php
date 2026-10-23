<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use TaxEbay\Core\Shipping\InsertText;
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\Parcels;
use TaxEbay\Core\Shipping\ShippingClient;
use TaxEbay\Core\Shipping\Stages;

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
    public const STAGE = 'taxebay_stage';

    /**
     * Label print onto 62 mm tape (assets/label62.js) is parked: his scanner could not read the matrix code from it
     * (2026-10-23). The code stays; the buttons are off until it does.
     */
    public const LABEL_PRINT = false;

    /**
     * @param list<OpenShipment>|string $open the sales, or why they could not be read
     * @param list<array{ok: bool, text: string}> $notices
     * @param array<string, array{stage: string, at: int}> $stages his stage notes (D-985)
     */
    public function page(array|string $open, array $notices, string $seller, array $stages, string $tab, int $now): string
    {
        $tab = in_array($tab, Stages::ALL, true) ? $tab : Stages::OPEN;
        $html = '<div class="wrap"><h1>' . esc_html__('eBay shipping', 'taxebay') . ' <small style="font-size:.6em;color:#646970">' . esc_html(Plugin::VERSION) . '</small></h1>';

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

        $byStage = array_fill_keys(Stages::ALL, []);

        foreach (Parcels::of($open) as $parcel) {
            $stage = Stages::of($parcel, $stages, $now);

            if ($stage !== null) {
                $byStage[$stage][] = $parcel;
            }
        }

        $html .= '<nav class="nav-tab-wrapper">';

        foreach (Stages::ALL as $stage) {
            $html .= '<a class="nav-tab' . ($stage === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=' . self::PAGE . '&tab=' . $stage)) . '">'
                . esc_html(self::stageName($stage)) . ' <span class="count">(' . count($byStage[$stage]) . ')</span></a>';
        }

        $html .= '</nav>';

        if ($byStage[$tab] === []) {
            return $html . '<p>' . esc_html__('Nothing here.', 'taxebay') . '</p></div>';
        }

        $html .= '<table class="widefat striped" style="margin-top:12px"><thead><tr>'
            . '<th>' . esc_html__('Order', 'taxebay') . '</th><th>' . esc_html__('Buyer', 'taxebay') . '</th><th>' . esc_html__('Items', 'taxebay') . '</th>'
            . '<th>' . esc_html__('Total', 'taxebay') . '</th><th>' . esc_html__('Tracking number', 'taxebay') . '</th><th>' . esc_html__('Actions', 'taxebay') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($byStage[$tab] as $parcel) {
            $html .= $this->row($parcel, $seller, $tab);
        }

        return $html . '</tbody></table></div>';
    }

    private static function stageName(string $stage): string
    {
        return match ($stage) {
            Stages::PACKED   => __('Packed', 'taxebay'),
            Stages::SHIPPED  => __('Shipped', 'taxebay'),
            Stages::RECEIVED => __('Received', 'taxebay'),
            default          => __('Open', 'taxebay'),
        };
    }

    /** @param non-empty-list<OpenShipment> $parcel sales to one buyer at one address — one parcel */
    private function row(array $parcel, string $seller, string $stage): string
    {
        $first = $parcel[0];
        $ids = array_map(static fn (OpenShipment $sale): string => $sale->orderId, $parcel);
        $orders = implode(',', $ids);
        $paid = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->paid, true);
        $labelled = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->labelled, true);
        $items = '';
        $totals = [];

        foreach ($parcel as $sale) {
            foreach ($sale->items as $item) {
                $items .= esc_html($item['quantity'] . ' × ' . $item['title']) . '<br>';
            }

            $totals[] = $sale->total === null ? '' : $sale->total . ' ' . $sale->currency;
        }

        $tracking = implode(', ', array_filter(array_map(static fn (OpenShipment $sale): string => $sale->tracking, $parcel)));
        $early = in_array($stage, [Stages::OPEN, Stages::PACKED], true);
        $actions = '';

        if ($early) {
            $actions .= '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank" style="margin-bottom:6px">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::INSERT) . '"><input type="hidden" name="orders" value="' . esc_attr($orders) . '">'
                . wp_nonce_field(self::INSERT, '_wpnonce', false, false)
                . '<input type="url" name="github" style="width:16em" placeholder="' . esc_attr__('GitHub project (optional)', 'taxebay') . '"> '
                . get_submit_button(__('Print insert', 'taxebay'), 'secondary small', 'print', false) . '</form>';
        }

        if ($early && ! $labelled) {
            $carriers = '';

            foreach (ShippingClient::CARRIERS as $code => $label) {
                $carriers .= '<option value="' . esc_attr($code) . '">' . esc_html($label) . '</option>';
            }

            $actions .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:6px" onsubmit="return confirm(' . esc_attr((string) wp_json_encode(
                /* translators: %s: eBay order numbers */
                sprintf(__('Report %s as shipped to eBay? The buyer is notified.', 'taxebay'), implode(', ', $ids))
            )) . ')">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::SHIP) . '"><input type="hidden" name="orders" value="' . esc_attr($orders) . '">'
                . wp_nonce_field(self::SHIP, '_wpnonce', true, false)
                . '<select name="carrier" aria-label="' . esc_attr__('Carrier', 'taxebay') . '">' . $carriers . '</select> '
                . '<input type="text" name="tracking" style="width:12em" required placeholder="' . esc_attr__('Tracking number', 'taxebay') . '"> '
                . get_submit_button(__('Mark shipped', 'taxebay'), 'primary small', 'submit', false, $paid ? null : ['disabled' => 'disabled']) . '</form>';
        }

        if (self::LABEL_PRINT && $early) {
            $actions .= '<label class="button button-small">' . esc_html__('Label PDF/image → 62 mm', 'taxebay')
                . '<input type="file" accept="application/pdf,image/*" hidden data-taxebay-label="' . esc_attr(self::reference($ids, $seller)) . '"></label> '
                . '<button type="button" class="button button-small" data-taxebay-paste="' . esc_attr(self::reference($ids, $seller)) . '">' . esc_html__('Paste screenshot → 62 mm', 'taxebay') . '</button> ';
        }

        $back = Stages::previous($stage);
        $next = Stages::next($stage);

        // Without an eBay label, «shipped» comes only with the tracking number reported above.
        if ($next === Stages::SHIPPED && ! $labelled) {
            $next = null;
        }

        foreach ([$back, $next] as $target) {
            if ($target !== null) {
                $actions .= $this->stageButton($orders, $target, $target === $back ? '← ' . self::stageName($target) : self::stageName($target) . ' →', $target === $next);
            }
        }

        return '<tr><td><strong>' . implode('<br>', array_map('esc_html', $ids)) . '</strong><br><small>' . esc_html($first->orderedAt) . ' · '
            . esc_html($paid ? __('paid', 'taxebay') : __('not all paid yet', 'taxebay')) . '</small></td>'
            . '<td>' . esc_html($first->buyer) . '<br><small>' . implode('<br>', array_map('esc_html', $first->address)) . '</small></td>'
            . '<td>' . $items . '<small>' . esc_html($first->service) . '</small></td>'
            . '<td>' . esc_html(implode(' + ', array_filter($totals))) . '</td>'
            . '<td>' . ($tracking === '' ? '—' : '<code>' . esc_html($tracking) . '</code>')
            . ($labelled ? '<br><small>' . esc_html__('eBay label', 'taxebay') . '</small>' : '') . '</td>'
            . '<td>' . $actions . '</td></tr>';
    }

    private function stageButton(string $orders, string $stage, string $label, bool $primary): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::STAGE) . '"><input type="hidden" name="orders" value="' . esc_attr($orders) . '">'
            . '<input type="hidden" name="stage" value="' . esc_attr($stage) . '">'
            . wp_nonce_field(self::STAGE, '_wpnonce', true, false)
            . get_submit_button($label, $primary ? 'primary small' : 'small', 'submit', false) . '</form>';
    }

    /**
     * The note for the parcel: what is inside and where the documentation lives — the web addresses eBay does not allow
     * in the listing itself (concept §1). Until offers are built (step 2) it points to the site. Two sizes: A6 for the
     * office printer, 62 mm endless tape for his Brother QL-810W.
     *
     * @param non-empty-list<OpenShipment> $parcel
     */
    public function insert(array $parcel, string $format, string $seller, string $project = ''): string
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
        // Page margin 0 keeps the browser's header and footer off; the sheet keeps its own padding.
        $page = $format === Settings::INSERT_LABEL
            ? '@page{size:62mm 100mm;margin:0}.sheet{width:62mm;height:100mm;padding:4.5mm 3mm;font-size:8pt}img{width:18mm}'
            : '@page{size:A6;margin:0}.sheet{width:105mm;height:148mm;padding:8mm;font-size:11pt}img{width:30mm}';

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($parcel[0]->orderId) . '</title>'
            // His signature in handwriting (his wish, 2026-10-23); without the web font the browser's own cursive.
            . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@600&display=block">'
            . '<style>' . $page . 'html,body{margin:0}.sheet{box-sizing:border-box;overflow:hidden;font-family:sans-serif;line-height:1.3}'
            . 'h1{font-size:1.2em;margin:0 0 .4em}ul{padding-left:1.2em;margin:.4em 0}p{margin:.4em 0}img{float:right;margin:0 0 .3em .3em}.project{clear:both;overflow-wrap:anywhere}'
            . '.sign{font-family:"Dancing Script",cursive;font-size:1.6em;line-height:1.1}</style>'
            // One sheet, always: the text shrinks until it fits (long item titles, several items in one parcel).
            . '<script>function fit(){var s=document.querySelector(".sheet"),size=parseFloat(getComputedStyle(s).fontSize);'
            . 'while(s.scrollHeight>s.clientHeight&&size>5){size-=.5;s.style.fontSize=size+"px";}print();}</script></head>'
            . '<body onload="document.fonts.ready.then(fit)"><div class="sheet"><img src="' . esc_attr($qr) . '" alt=""><h1>' . esc_html($words['thanks']) . '</h1>'
            . '<ul>' . $items . '</ul><p>' . esc_html($words['docs']) . '<br><strong>' . esc_html($site) . '</strong></p>'
            . ($project === '' ? '' : '<p class="project"><img src="' . esc_attr((new QRCode(new QROptions(['outputType' => 'svg', 'outputBase64' => true])))->render($project)) . '" alt="">'
                . esc_html($words['project']) . '<br><strong>' . esc_html($project) . '</strong></p>')
            . '<p><small>' . esc_html($words['private']) . '<br>' . esc_html(self::reference($ids, $seller, $words)) . '</small></p>'
            . '<p class="sign">' . esc_html($words['enjoy']) . '<br>SFA</p></div></body></html>';
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
