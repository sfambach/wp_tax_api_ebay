<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use TaxEbay\Core\Shipping\InsertText;
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\ParcelList;
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
    public const LABEL_PDF = 'taxebay_label_pdf';

    /**
     * Label print from an uploaded file or a screenshot is parked: his scanner could not read the matrix code from a
     * screenshot (2026-10-23). The PDF behind DHL's mail link reads cleanly, so that way is on.
     */
    public const LABEL_PRINT = false;

    /** A parcel this long in «shipped» without arriving is worth asking after. */
    private const OVERDUE_DAYS = 7;

    private const BULK_FORM = 'taxebay-bulk';
    private const FILTER_FORM = 'taxebay-filter';

    /**
     * @param list<OpenShipment>|string $open the sales, or why they could not be read
     * @param list<array{ok: bool, text: string}> $notices
     * @param array<string, array{stage: string, at: int}> $stages his stage notes (D-985)
     * @param list<array{cart: string, date: string, links: list<string>}> $dhlLabels label links tax_mail found in DHL's mails, newest first
     * @param array<string, array<string, mixed>> $carrier order id → what the DHL module says; `mixed`: its array
     * @param array<string, string> $labelLinks order id → the DHL label link whose recipient is this parcel's
     */
    public function page(array|string $open, array $notices, string $seller, array $stages, string $tab, int $now, ParcelList $list = new ParcelList(), array $dhlLabels = [], array $carrier = [], array $labelLinks = []): string
    {
        $tab = in_array($tab, Stages::ALL, true) ? $tab : Stages::OPEN;
        $html = '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('eBay shipping', 'taxebay') . ' <small style="font-size:.6em;color:#646970">' . esc_html(Plugin::VERSION) . '</small></h1>';

        if (trim($list->search) !== '') {
            /* translators: %s: search words */
            $html .= '<span class="subtitle">' . esc_html(sprintf(__('Search results for: %s', 'taxebay'), $list->search)) . '</span>';
        }

        $html .= '<hr class="wp-header-end">';

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
            $html .= '<a class="nav-tab' . ($stage === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url($list, ['tab' => $stage])) . '">'
                . esc_html(self::stageName($stage)) . ' <span class="count">(' . count($list->apply($byStage[$stage])) . ')</span></a>';
        }

        $html .= '</nav>';
        $shown = $list->apply($byStage[$tab]);
        $html .= $this->filters($list, $tab, count($shown));

        $html .= '<table class="wp-list-table widefat fixed striped table-view-list"><thead>' . $this->headings($list, $tab) . '</thead><tbody>';

        if ($shown === []) {
            $html .= '<tr class="no-items"><td class="colspanchange" colspan="7">' . esc_html__('Nothing here.', 'taxebay') . '</td></tr>';
        }

        foreach ($shown as $parcel) {
            $html .= $this->row($parcel, $seller, $tab, $stages, $now, $carrier[$parcel[0]->orderId] ?? null, $labelLinks[$parcel[0]->orderId] ?? '');
        }

        return $html . '</tbody><tfoot>' . $this->headings($list, $tab) . '</tfoot></table>' . self::dhlChoices($dhlLabels) . '</div>';
    }

    /**
     * The links tax_mail read from DHL's mails, offered in every label field; which parcel a label belongs to he sees
     * on the printout (the mail does not say).
     *
     * @param list<array{cart: string, date: string, links: list<string>}> $dhlLabels
     */
    private static function dhlChoices(array $dhlLabels): string
    {
        $options = '';

        foreach ($dhlLabels as $label) {
            $at = strtotime($label['date']);

            foreach ($label['links'] as $number => $link) {
                $options .= '<option value="' . esc_attr($link) . '" label="' . esc_attr(trim($label['cart'] . ' · ' . ($at === false ? '' : wp_date('d.m. H:i', $at)) . (count($label['links']) > 1 ? ' #' . ($number + 1) : ''), ' ·')) . '"></option>';
            }
        }

        return '<datalist id="taxebay-dhl-labels">' . $options . '</datalist>';
    }

    /**
     * The page address with his current search, filters and order, changed by `$change`.
     *
     * @param array<string, string> $change
     */
    private static function url(ParcelList $list, array $change): string
    {
        $query = array_filter([
            'page'    => self::PAGE,
            's'       => trim($list->search),
            'label'   => $list->label,
            'paid'    => $list->paid,
            'orderby' => $list->orderBy === 'date' ? '' : $list->orderBy,
            'order'   => $list->descending ? 'desc' : '',
        ]);

        return admin_url('admin.php?' . http_build_query(array_filter(array_merge($query, $change)), '', '&'));
    }

    /** Search box, filter drop-downs and bulk actions, laid out as a WordPress list table does it. */
    private function filters(ParcelList $list, string $tab, int $count): string
    {
        $select = static function (string $name, string $current, array $options, string $label, string $form): string {
            $html = '<label class="screen-reader-text" for="taxebay-' . esc_attr($name) . '">' . esc_html($label) . '</label><select name="' . esc_attr($name) . '" id="taxebay-' . esc_attr($name) . '" form="' . esc_attr($form) . '">';

            foreach ($options as $value => $text) {
                $html .= '<option value="' . esc_attr((string) $value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . esc_html($text) . '</option>';
            }

            return $html . '</select>';
        };

        $bulk = ['' => __('Bulk actions', 'taxebay')];

        foreach (Stages::ALL as $stage) {
            if ($stage !== $tab) {
                /* translators: %s: name of a tab */
                $bulk[$stage] = sprintf(__('Move to «%s»', 'taxebay'), self::stageName($stage));
            }
        }

        /* translators: %d: number of parcels */
        $items = '<div class="tablenav-pages one-page"><span class="displaying-num">' . esc_html(sprintf(_n('%d parcel', '%d parcels', $count, 'taxebay'), $count)) . '</span></div>';

        return '<form method="get" id="' . self::FILTER_FORM . '" action="' . esc_url(admin_url('admin.php')) . '">'
            . '<input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">'
            . ($list->orderBy === 'date' ? '' : '<input type="hidden" name="orderby" value="' . esc_attr($list->orderBy) . '">')
            . ($list->descending ? '<input type="hidden" name="order" value="desc">' : '')
            . '<p class="search-box"><label class="screen-reader-text" for="taxebay-search">' . esc_html__('Search parcels', 'taxebay') . '</label>'
            . '<input type="search" id="taxebay-search" name="s" value="' . esc_attr($list->search) . '" placeholder="' . esc_attr__('Order, buyer, item, tracking…', 'taxebay') . '"> '
            . get_submit_button(__('Search parcels', 'taxebay'), '', '', false, ['id' => 'search-submit']) . '</p></form>'
            . '<div class="tablenav top">'
            . '<form method="post" id="' . self::BULK_FORM . '" action="' . esc_url(admin_url('admin-post.php')) . '" class="alignleft actions bulkactions">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::STAGE) . '">'
            . wp_nonce_field(self::STAGE, '_wpnonce', true, false)
            . $select('stage', '', $bulk, __('Bulk actions', 'taxebay'), self::BULK_FORM)
            . get_submit_button(__('Apply', 'taxebay'), 'action', '', false) . '</form>'
            . '<div class="alignleft actions">'
            . $select('label', $list->label, ['' => __('All labels', 'taxebay'), ParcelList::LABEL_EBAY => __('eBay label', 'taxebay'), ParcelList::LABEL_OWN => __('Own label', 'taxebay')], __('Label', 'taxebay'), self::FILTER_FORM)
            . $select('paid', $list->paid, ['' => __('Paid and unpaid', 'taxebay'), ParcelList::PAID => __('Paid', 'taxebay'), ParcelList::UNPAID => __('Not paid yet', 'taxebay')], __('Payment', 'taxebay'), self::FILTER_FORM)
            . get_submit_button(__('Filter', 'taxebay'), '', 'filter_action', false, ['form' => self::FILTER_FORM])
            . '</div>'
            . $items . '<br class="clear"></div>';
    }

    private function headings(ParcelList $list, string $tab): string
    {
        $sortable = static function (string $key, string $label) use ($list): string {
            $current = $list->orderBy === $key;
            $next = $current && ! $list->descending ? 'desc' : 'asc';

            return '<th scope="col" class="manage-column ' . ($current ? 'sorted ' . ($list->descending ? 'desc' : 'asc') : 'sortable ' . ($next === 'asc' ? 'desc' : 'asc')) . '">'
                . '<a href="' . esc_url(self::url($list, ['orderby' => $key === 'date' ? '' : $key, 'order' => $next === 'desc' ? 'desc' : ''])) . '"><span>' . esc_html($label) . '</span>'
                . '<span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>';
        };

        return '<tr><td class="manage-column column-cb check-column"><input type="checkbox" aria-label="' . esc_attr__('Select all', 'taxebay') . '"></td>'
            . str_replace('<th ', '<th style="width:12em" ', $sortable('date', __('Order', 'taxebay')))
            . $sortable('buyer', __('Buyer', 'taxebay'))
            . '<th scope="col" class="manage-column" style="width:25%">' . esc_html__('Items', 'taxebay') . '</th>'
            . str_replace('<th ', '<th style="width:7em" ', $sortable('total', __('Total', 'taxebay')))
            . '<th scope="col" class="manage-column">' . esc_html__('Tracking number', 'taxebay') . '</th>'
            . '<th scope="col" class="manage-column" style="width:' . ($tab === Stages::OPEN || $tab === Stages::PACKED ? '30' : '16') . '%">' . esc_html__('Actions', 'taxebay') . '</th></tr>';
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

    /**
     * @param non-empty-list<OpenShipment> $parcel sales to one buyer at one address — one parcel
     * @param array<string, array{stage: string, at: int}> $stages
     * @param array<string, mixed>|null $carrier the DHL module's word on the tracking number
     */
    private function row(array $parcel, string $seller, string $stage, array $stages, int $now, ?array $carrier = null, string $labelLink = ''): string
    {
        $first = $parcel[0];
        $ids = array_map(static fn (OpenShipment $sale): string => $sale->orderId, $parcel);
        $orders = implode(',', $ids);
        $paid = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->paid, true);
        $labelled = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->labelled, true);
        $items = '';

        foreach ($parcel as $sale) {
            foreach ($sale->items as $item) {
                $items .= esc_html($item['quantity'] . ' × ' . $item['title']) . '<br>';
            }
        }

        $since = $stages[$first->orderId]['at'] ?? null;
        $overdue = $stage === Stages::SHIPPED && $since !== null && $since < $now - self::OVERDUE_DAYS * 86400;
        $tracking = implode(', ', array_filter(array_map(static fn (OpenShipment $sale): string => $sale->tracking, $parcel)));
        $early = in_array($stage, [Stages::OPEN, Stages::PACKED], true);
        $actions = '';

        if ($early) {
            $actions .= '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank" style="margin-bottom:6px">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::INSERT) . '"><input type="hidden" name="orders" value="' . esc_attr($orders) . '">'
                . wp_nonce_field(self::INSERT, '_wpnonce', false, false)
                . '<input type="url" name="github" style="width:14em;max-width:100%" placeholder="' . esc_attr__('GitHub project (optional)', 'taxebay') . '"> '
                . get_submit_button(__('Print insert', 'taxebay'), 'small', 'print', false) . '</form>';
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
                . '<input type="text" name="tracking" style="width:10em" required placeholder="' . esc_attr__('Tracking number', 'taxebay') . '"> '
                . get_submit_button(__('Mark shipped', 'taxebay'), 'primary small', 'submit', false, $paid ? null : ['disabled' => 'disabled']) . '</form>';
        }

        if ($early && $labelled) {
            $actions .= '<div style="margin-bottom:6px"><input type="url" data-taxebay-link-input list="taxebay-dhl-labels" value="' . esc_attr($labelLink) . '" style="width:14em;max-width:100%" placeholder="' . esc_attr__('DHL label link (from the mail)', 'taxebay') . '"> '
                . '<button type="button" class="button button-small" data-taxebay-link="' . esc_attr(self::reference($ids, $seller)) . '">' . esc_html__('Print label 62 mm', 'taxebay') . '</button>'
                . ($labelLink === '' ? '' : '<br><small>' . esc_html__('Label found in DHL\'s mail (recipient matches).', 'taxebay') . '</small>') . '</div>';
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

        $links = [];

        foreach ($ids as $id) {
            $links[] = '<a href="' . esc_url('https://www.ebay.de/sh/ord/details?orderid=' . rawurlencode($id)) . '" target="_blank" rel="noopener">' . esc_html($id) . '</a>';
        }

        $rowActions = '<div class="row-actions">'
            . ($stage === Stages::RECEIVED ? '<span><a href="' . esc_url('https://www.ebay.de/fdbk/leave_feedback') . '" target="_blank" rel="noopener">' . esc_html__('Leave feedback', 'taxebay') . '</a> | </span>' : '')
            . '<span><a href="' . esc_url('https://www.ebay.de/sh/ord/details?orderid=' . rawurlencode($first->orderId)) . '" target="_blank" rel="noopener">' . esc_html__('Open at eBay', 'taxebay') . '</a></span></div>';

        return '<tr' . ($overdue ? ' style="box-shadow:inset 4px 0 0 #d63638"' : '') . '>'
            . '<th scope="row" class="check-column"><input type="checkbox" name="orders[]" form="' . self::BULK_FORM . '" value="' . esc_attr($orders) . '" aria-label="' . esc_attr($orders) . '"></th>'
            . '<td><strong>' . implode('<br>', $links) . '</strong><br><small>' . esc_html($first->orderedAt) . ' · '
            . ($paid ? esc_html__('paid', 'taxebay') : '<span style="color:#d63638">' . esc_html__('not all paid yet', 'taxebay') . '</span>') . '</small>'
            . ($overdue ? '<br><small style="color:#d63638">' . esc_html(sprintf(
                /* translators: %d: days */
                __('shipped %d days ago — ask after it?', 'taxebay'),
                intdiv($now - (int) $since, 86400)
            )) . '</small>' : '')
            . $rowActions . '</td>'
            . '<td>' . esc_html($first->buyer) . '<br><small>' . implode('<br>', array_map('esc_html', $first->address)) . '</small></td>'
            . '<td>' . $items . '<small>' . esc_html($first->service) . '</small></td>'
            . '<td>' . esc_html(number_format(ParcelList::total($parcel), 2, ',', '.') . ' ' . $first->currency) . '</td>'
            . '<td>' . ($tracking === '' ? '—' : '<code>' . esc_html($tracking) . '</code>')
            . '<br><small>' . esc_html($labelled ? __('eBay label', 'taxebay') : __('Own label', 'taxebay')) . '</small>'
            . ($carrier === null ? '' : '<br><small title="' . esc_attr((string) ($carrier['at'] ?? '')) . '"' . (($carrier['stage'] ?? '') === 'problem' ? ' style="color:#d63638"' : '') . '>DHL: ' . esc_html((string) ($carrier['status'] ?? $carrier['stage'] ?? '')) . '</small>')
            . '</td>'
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
