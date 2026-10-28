<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Taxmod\WordPress\Admin\ListPage;
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

    /** A parcel this long in «shipped» without arriving is worth asking after. */
    private const OVERDUE_DAYS = 7;

    private const BULK_FORM = 'taxebay-bulk';
    private const SEARCH_FORM = 'taxebay-search';

    /** Bulk moves post to `admin_post_<this><stage>`; ListPage's bulk bar names the handler in its action field. */
    public const BULK_TO = 'taxebay_to_';
    public const DHL_REFRESH = 'taxebay_dhl_refresh';
    /** DHL answers one call every five seconds; ten keep a click under a minute (wp_tax_api_dhl 0.0.10). */
    public const REFRESH_AT_ONCE = 10;
    /** Query argument naming the parcel whose history opens after a refresh. */
    public const OPEN_HISTORY = 'taxebay_open';

    /**
     * The shipping list in the modeller's shared list page (D-989): tabs by stage (D-985), search and filters, rows with
     * their buttons on the right and the details below.
     *
     * @param list<OpenShipment>|string $open the sales, or why they could not be read
     * @param list<array{ok: bool, text: string}> $notices
     * @param array<string, array{stage: string, at: int}> $stages his stage notes (D-985)
     * @param list<array{cart: string, date: string, links: list<string>}> $dhlLabels label links tax_mail found in DHL's mails, newest first
     * @param array<string, array<string, mixed>> $carrier order id → what the DHL module says; `mixed`: its array
     * @param array<string, string> $labelLinks order id → the DHL label link whose recipient is this parcel's
     * @param array<string, list<array{kind: string, id: string, status: string, respond_by: string}>> $disputes order id → open inquiries and cases
     */
    public function page(array|string $open, array $notices, string $seller, array $stages, string $tab, int $now, ParcelList $list = new ParcelList(), array $dhlLabels = [], array $carrier = [], array $labelLinks = [], array $disputes = []): string
    {
        if (! method_exists(ListPage::class, 'searchBox')) {
            return '<div class="wrap"><h1>' . esc_html__('eBay shipping', 'taxebay') . '</h1><div class="notice notice-error inline"><p>'
                . esc_html__('This page needs Taxonomy Modeller 0.1.65 or newer.', 'taxebay') . '</p></div></div>';
        }

        $tab = in_array($tab, Stages::ALL, true) ? $tab : Stages::OPEN;
        $html = '<div class="wrap">' . ListPage::heading(__('eBay shipping', 'taxebay'), Plugin::VERSION);

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
            $byStage[Stages::of($parcel, $stages, $now)][] = $parcel;
        }

        $tabs = [];
        $bulk = [];

        foreach (Stages::ALL as $stage) {
            $tabs[] = [$stage, self::stageName($stage), count($list->apply($byStage[$stage]))];

            if ($stage !== $tab) {
                /* translators: %s: name of a tab */
                $bulk[self::BULK_TO . $stage] = sprintf(__('Move to «%s»', 'taxebay'), self::stageName($stage));
            }
        }

        $shown = $list->apply($byStage[$tab]);
        $html .= ListPage::tabs($tabs, $tab, static fn (string $stage): string => self::url($list, ['tab' => $stage]))
            . ListPage::searchBox(self::SEARCH_FORM, $list->search, __('Order, buyer, item, tracking…', 'taxebay'), array_filter(['page' => self::PAGE, 'tab' => $tab, 'orderby' => $list->orderBy === 'date' ? '' : $list->orderBy, 'order' => $list->descending ? 'desc' : '']), __('Search parcels', 'taxebay'))
            . ListPage::bulkBar(
                self::BULK_FORM,
                self::STAGE,
                $bulk,
                /* translators: %d: number of parcels */
                sprintf(_n('%d parcel', '%d parcels', count($shown), 'taxebay'), count($shown)),
                ListPage::filters(self::SEARCH_FORM, [
                    ['label', ['' => __('All labels', 'taxebay'), ParcelList::LABEL_EBAY => __('eBay label', 'taxebay'), ParcelList::LABEL_OWN => __('Own label', 'taxebay')], $list->label, __('Label', 'taxebay')],
                    ['paid', ['' => __('Paid and unpaid', 'taxebay'), ParcelList::PAID => __('Paid', 'taxebay'), ParcelList::UNPAID => __('Not paid yet', 'taxebay')], $list->paid, __('Payment', 'taxebay')],
                ])
            );

        $rows = '';

        foreach ($shown as $parcel) {
            $rows .= $this->row($parcel, $seller, $tab, $stages, $now, $carrier[$parcel[0]->orderId] ?? null, $labelLinks[$parcel[0]->orderId] ?? '', array_merge(...array_map(static fn (OpenShipment $sale): array => $disputes[$sale->orderId] ?? [], $parcel)));
        }

        if ($rows === '') {
            $rows = '<tr class="no-items"><td class="colspanchange" colspan="7">' . esc_html__('Nothing here.', 'taxebay') . '</td></tr>';
        }

        $html .= self::refreshAll($shown, $tab, $carrier);

        return $html . ListPage::table([
            self::column($list, 'date', __('Order', 'taxebay'), '12em'),
            self::column($list, 'buyer', __('Buyer', 'taxebay'), '13em'),
            [__('Items', 'taxebay')],
            self::column($list, 'total', __('Total', 'taxebay'), '7em'),
            [__('Tracking number', 'taxebay'), '12em'],
        ], $rows) . self::dhlChoices($dhlLabels) . '</div>';
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} a sortable column for ListPage::table() */
    private static function column(ParcelList $list, string $key, string $label, string $width): array
    {
        $current = $list->orderBy === $key;

        return [$label, $width, self::url($list, ['orderby' => $key === 'date' ? '' : $key, 'order' => $current && ! $list->descending ? 'desc' : '']), $current ? ($list->descending ? 'desc' : 'asc') : ''];
    }

    /**
     * The links tax_mail read from DHL's mails, offered in every label field when no label matched.
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
     * One parcel: what to know at a glance in the cells, the next steps as buttons on the right, the rest below.
     *
     * @param non-empty-list<OpenShipment> $parcel sales to one buyer at one address — one parcel
     * @param array<string, array{stage: string, at: int}> $stages
     * @param array<string, mixed>|null $carrier the DHL module's word on the tracking number
     * @param list<array{kind: string, id: string, status: string, respond_by: string}> $disputes open inquiries and cases of the parcel's orders
     */
    private function row(array $parcel, string $seller, string $stage, array $stages, int $now, ?array $carrier, string $labelLink, array $disputes = []): string
    {
        $first = $parcel[0];
        $ids = array_map(static fn (OpenShipment $sale): string => $sale->orderId, $parcel);
        $orders = implode(',', $ids);
        $key = 'taxebay-' . md5($orders);
        $paid = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->paid, true);
        $labelled = array_reduce($parcel, static fn (bool $all, OpenShipment $sale): bool => $all && $sale->labelled, true);
        $early = in_array($stage, [Stages::OPEN, Stages::PACKED], true);
        $since = $stages[$first->orderId]['at'] ?? null;
        $overdue = $stage === Stages::SHIPPED && $since !== null && $since < $now - self::OVERDUE_DAYS * 86400;
        $tracking = implode(', ', array_unique(array_filter(array_map(static fn (OpenShipment $sale): string => $sale->tracking, $parcel))));
        $items = [];

        foreach ($parcel as $sale) {
            foreach ($sale->items as $item) {
                $items[] = esc_html($item['quantity'] . ' × ' . $item['title']);
            }
        }

        $place = trim((string) ($first->address[count($first->address) - 2] ?? ''));
        $cells = [
            '<strong>' . implode('<br>', array_map(self::orderLink(...), $ids)) . '</strong><br><small>' . esc_html($first->orderedAt) . ' · '
                . ($paid ? esc_html__('paid', 'taxebay') : '<span style="color:#d63638">' . esc_html__('not all paid yet', 'taxebay') . '</span>') . '</small>'
                . ($overdue ? '<br><small style="color:#d63638">' . esc_html(sprintf(
                    /* translators: %d: days */
                    __('shipped %d days ago — ask after it?', 'taxebay'),
                    intdiv($now - (int) $since, 86400)
                )) . '</small>' : '')
                . self::disputeNotes($disputes),
            esc_html($first->buyer) . ($place === '' ? '' : '<br><small>' . esc_html($place) . '</small>'),
            implode('<br>', $items),
            esc_html(number_format(ParcelList::total($parcel), 2, ',', '.') . ' ' . $first->currency),
            ($tracking === '' ? '—' : self::trackingHistory($tracking, $carrier, $ids, $stage)) . '<br><small>' . esc_html($labelled ? __('eBay label', 'taxebay') : __('Own label', 'taxebay')) . '</small>'
                . ($carrier === null ? '' : (self::hasEvents($carrier) ? '' : '<br><small' . (($carrier['stage'] ?? '') === 'problem' ? ' style="color:#d63638"' : '') . '>DHL: ' . esc_html(self::carrierWord($carrier)) . '</small>')
                    . (isset($carrier['checked_at']) && is_numeric($carrier['checked_at']) ? '<br><small style="color:#646970">' . esc_html(sprintf(
                        /* translators: %s: date and time */
                        __('as of %s', 'taxebay'),
                        wp_date('d.m. H:i', (int) $carrier['checked_at'])
                    )) . '</small>' : '')),
        ];

        $buttons = '';

        if ($early) {
            $buttons .= '<a class="button button-small" target="_blank" href="' . esc_url(self::insertUrl($orders)) . '" title="' . esc_attr__('Print insert', 'taxebay') . '" aria-label="' . esc_attr__('Print insert', 'taxebay') . '">' . self::icon('media-text') . '</a> ';
        }

        if ($early && $labelled) {
            $buttons .= '<button type="button" class="button button-small" data-taxebay-link="' . esc_attr(self::reference($ids, $seller)) . '" data-taxebay-input="' . esc_attr($key . '-label') . '" title="' . esc_attr__('Print label 62 mm', 'taxebay') . '" aria-label="' . esc_attr__('Print label 62 mm', 'taxebay') . '">' . self::icon('printer') . '</button> ';
        }

        $back = Stages::previous($stage);
        $next = Stages::next($stage);

        // Without an eBay label, «shipped» comes only with the tracking number reported in the details.
        if ($next === Stages::SHIPPED && ! $labelled) {
            $next = null;
        }

        if ($back !== null) {
            $buttons .= $this->stageButton($orders, $back, '←', false, sprintf(
                /* translators: %s: name of a tab */
                __('Back to «%s»', 'taxebay'),
                self::stageName($back)
            ));
        }

        if ($next !== null) {
            $buttons .= $this->stageButton($orders, $next, self::stageName($next) . ' →', true, '');
        }

        return ListPage::row(self::BULK_FORM, $orders, $cells, $buttons, $this->details($parcel, $ids, $orders, $key, $early, $labelled, $paid, $labelLink, $stage));
    }

    /**
     * Address, sales, the insert with a project link, the label link, «Mark shipped» and the links to eBay.
     *
     * @param non-empty-list<OpenShipment> $parcel
     * @param list<string> $ids
     */
    private function details(array $parcel, array $ids, string $orders, string $key, bool $early, bool $labelled, bool $paid, string $labelLink, string $stage): string
    {
        $first = $parcel[0];
        $lines = [];

        foreach ($parcel as $sale) {
            $lines[] = esc_html(trim($sale->orderId . ' · ' . $sale->service . ' · ' . ($sale->total === null ? '' : $sale->total . ' ' . $sale->currency)
                . ($sale->refunded === null ? '' : ' (' . sprintf(
                    /* translators: %s: amount with currency */
                    __('%s refunded', 'taxebay'),
                    $sale->refunded . ' ' . $sale->currency
                ) . ')'), ' ·'));
        }

        $tools = '';

        if ($early) {
            $tools .= '<form method="get" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank" style="margin-bottom:8px">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::INSERT) . '"><input type="hidden" name="orders" value="' . esc_attr($orders) . '">'
                . wp_nonce_field(self::INSERT, '_wpnonce', false, false)
                . '<input type="url" name="github" style="width:22em;max-width:100%" placeholder="' . esc_attr__('GitHub project (optional)', 'taxebay') . '"> '
                . get_submit_button(__('Print insert with project', 'taxebay'), 'small', 'print', false) . '</form>';
        }

        if ($early && $labelled) {
            $tools .= '<p style="margin:0 0 8px"><label for="' . esc_attr($key . '-label') . '">' . esc_html__('DHL label link', 'taxebay') . '</label><br>'
                . '<input type="url" id="' . esc_attr($key . '-label') . '" list="taxebay-dhl-labels" value="' . esc_attr($labelLink) . '" style="width:22em;max-width:100%" placeholder="' . esc_attr__('from DHL\'s mail', 'taxebay') . '">'
                . ($labelLink === '' ? ' <small>' . esc_html__('no label matched yet — pick one or paste the link', 'taxebay') . '</small>' : ' <small>' . esc_html__('found in DHL\'s mail (recipient matches)', 'taxebay') . '</small>') . '</p>';
        }

        if ($early && ! $labelled) {
            $carriers = '';

            foreach (ShippingClient::CARRIERS as $code => $label) {
                $carriers .= '<option value="' . esc_attr($code) . '">' . esc_html($label) . '</option>';
            }

            $tools .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(' . esc_attr((string) wp_json_encode(
                /* translators: %s: eBay order numbers */
                sprintf(__('Report %s as shipped to eBay? The buyer is notified.', 'taxebay'), implode(', ', $ids))
            )) . ')">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::SHIP) . '"><input type="hidden" name="orders" value="' . esc_attr($orders) . '">'
                . wp_nonce_field(self::SHIP, '_wpnonce', true, false)
                . '<select name="carrier" aria-label="' . esc_attr__('Carrier', 'taxebay') . '">' . $carriers . '</select> '
                . '<input type="text" name="tracking" style="width:14em" required placeholder="' . esc_attr__('Tracking number', 'taxebay') . '"> '
                . get_submit_button(__('Mark shipped', 'taxebay'), 'primary small', 'submit', false, $paid ? null : ['disabled' => 'disabled']) . '</form>';
        }

        $links = '<a href="' . esc_url(self::ebayOrderUrl($first->orderId)) . '" target="_blank" rel="noopener">' . esc_html__('Open at eBay', 'taxebay') . '</a>'
            . ($stage === Stages::RECEIVED ? ' · <a href="' . esc_url('https://www.ebay.de/fdbk/leave_feedback') . '" target="_blank" rel="noopener">' . esc_html__('Leave feedback', 'taxebay') . '</a>' : '');

        return '<div style="display:flex;gap:32px;flex-wrap:wrap;padding-top:8px">'
            . '<address style="flex:0 1 16em;font-style:normal">' . implode('<br>', array_map('esc_html', $first->address)) . '</address>'
            . '<div style="flex:0 1 18em">' . implode('<br>', $lines) . '<p>' . $links . '</p></div>'
            . '<div style="flex:1 1 24em">' . $tools . '</div></div>';
    }

    /**
     * The tracking number: a click asks DHL anew and opens the parcel's history in the row (his wish, 2026-10-27). The
     * page itself shows only what DHL said last, so it loads fast.
     *
     * @param array<string, mixed>|null $carrier `mixed`: the DHL module's array; `events` came with its 0.0.8
     * @param list<string> $ids
     */
    private static function trackingHistory(string $tracking, ?array $carrier, array $ids, string $stage): string
    {
        $events = array_filter((array) ($carrier['events'] ?? []), 'is_array');
        $open = isset($_GET[self::OPEN_HISTORY]) && sanitize_text_field(wp_unslash($_GET[self::OPEN_HISTORY])) === $ids[0];
        $number = '<a href="' . esc_url(self::refreshUrl([self::parcelArgument($ids, $tracking)], $stage, $ids[0])) . '" title="'
            . esc_attr__('Ask DHL now and show the history', 'taxebay') . '"><code>' . esc_html($tracking) . '</code></a>';

        if ($events === []) {
            return $number;
        }

        // The newest event is the summary, the older ones open below it — DHL's word once, not twice (his note, 2026-10-27).
        $lines = array_map(self::eventLine(...), array_values($events));
        $newest = array_shift($lines);
        $style = ($carrier['stage'] ?? '') === 'problem' ? 'color:#d63638;' : '';

        if ($lines === []) {
            return $number . '<br><small style="' . $style . '">DHL: ' . $newest . '</small>';
        }

        return $number . '<details' . ($open ? ' open' : '') . '><summary style="cursor:pointer;' . $style . '"><small>DHL: ' . $newest . '</small></summary>'
            . '<ol style="margin:6px 0 0 1.2em;font-size:12px">' . implode('', array_map(static fn (string $line): string => '<li>' . $line . '</li>', $lines)) . '</ol></details>';
    }

    /** The order number opens the sale at eBay in a new tab (his wish, 2026-10-28). */
    private static function orderLink(string $orderId): string
    {
        return '<a href="' . esc_url(self::ebayOrderUrl($orderId)) . '" target="_blank" rel="noopener" title="' . esc_attr__('Open at eBay', 'taxebay') . '">' . esc_html($orderId) . '</a>';
    }

    private static function ebayOrderUrl(string $orderId): string
    {
        return 'https://www.ebay.de/sh/ord/details?orderid=' . rawurlencode($orderId);
    }

    /** @param array<string, mixed> $event one of the DHL module's events; `mixed`: its array */
    private static function eventLine(array $event): string
    {
        $when = (string) ($event['at'] ?? '');
        $time = strtotime($when);
        $text = trim((string) ($event['description'] ?? '')) ?: (string) ($event['status'] ?? '');
        $place = trim((string) ($event['place'] ?? ''));

        return '<small>' . esc_html($time === false ? $when : wp_date('d.m. H:i', $time)) . '</small> ' . esc_html($text)
            . ($place === '' ? '' : ' <small>· ' . esc_html($place) . '</small>');
    }

    /** @param array<string, mixed> $carrier `mixed`: the DHL module's array */
    private static function hasEvents(array $carrier): bool
    {
        return array_filter((array) ($carrier['events'] ?? []), 'is_array') !== [];
    }

    /** @param list<array{kind: string, id: string, status: string, respond_by: string}> $disputes */
    private static function disputeNotes(array $disputes): string
    {
        $notes = '';

        foreach ($disputes as $dispute) {
            $notes .= '<br><strong style="color:#d63638">' . esc_html($dispute['kind'] === 'case' ? __('Case open', 'taxebay') : __('Buyer inquiry open', 'taxebay'))
                . '</strong><small style="color:#d63638"> · ' . esc_html(strtolower(str_replace('_', ' ', $dispute['status'])))
                . ($dispute['respond_by'] === '' ? '' : ' · ' . esc_html(sprintf(
                    /* translators: %s: date */
                    __('answer by %s', 'taxebay'),
                    $dispute['respond_by']
                ))) . '</small>';
        }

        return $notes;
    }

    /**
     * «Refresh DHL» for the parcels of the tab with a tracking number, the longest unchecked first; only where DHL can
     * move it on. DHL allows one call every five seconds, so one click asks for at most REFRESH_AT_ONCE.
     *
     * @param list<non-empty-list<OpenShipment>> $parcels
     * @param array<string, array<string, mixed>> $carrier `mixed`: the DHL module's array
     */
    private static function refreshAll(array $parcels, string $tab, array $carrier): string
    {
        usort($parcels, static fn (array $a, array $b): int => (int) ($carrier[$a[0]->orderId]['checked_at'] ?? 0) <=> (int) ($carrier[$b[0]->orderId]['checked_at'] ?? 0));

        if (! in_array($tab, [Stages::PACKED, Stages::SHIPPED], true)) {
            return '';
        }

        $arguments = [];

        foreach ($parcels as $parcel) {
            $tracking = trim(explode(',', $parcel[0]->tracking)[0]);

            if ($tracking !== '') {
                $arguments[] = self::parcelArgument(array_map(static fn (OpenShipment $sale): string => $sale->orderId, $parcel), $tracking);
            }
        }

        if ($arguments === []) {
            return '';
        }

        $arguments = array_slice($arguments, 0, self::REFRESH_AT_ONCE);

        return '<p style="text-align:right;margin:0 0 8px"><a class="button" href="' . esc_url(self::refreshUrl($arguments, $tab, '')) . '">'
            . esc_html__('Refresh DHL and eBay', 'taxebay') . '</a></p>';
    }

    /** @param list<string> $ids */
    private static function parcelArgument(array $ids, string $tracking): string
    {
        return implode(',', $ids) . ':' . $tracking;
    }

    /** @param list<string> $parcels «order,order:tracking» each */
    private static function refreshUrl(array $parcels, string $tab, string $open): string
    {
        return add_query_arg(array_filter([
            'action'          => self::DHL_REFRESH,
            'parcels'         => implode(';', $parcels),
            'tab'             => $tab,
            self::OPEN_HISTORY => $open,
            '_wpnonce'        => wp_create_nonce(self::DHL_REFRESH),
        ]), admin_url('admin-post.php'));
    }

    /**
     * The DHL module's word for the parcel; since its 0.0.7 a number DHL has not scanned yet comes as known=false with
     * empty status fields — eBay's «tracking number given».
     *
     * @param array<string, mixed> $carrier `mixed`: the module's array
     */
    private static function carrierWord(array $carrier): string
    {
        $status = trim((string) ($carrier['status'] ?? ''));

        if ($status !== '') {
            return $status;
        }

        return ($carrier['known'] ?? true) === false ? __('unknown to DHL', 'taxebay') : (string) ($carrier['stage'] ?? '');
    }

    // Icons keep the row's buttons inside the fixed actions column of the shared list (D-989).
    private static function icon(string $name): string
    {
        return '<span class="dashicons dashicons-' . esc_attr($name) . '" style="font-size:16px;width:16px;height:16px;vertical-align:middle" aria-hidden="true"></span>';
    }

    private static function insertUrl(string $orders): string
    {
        return add_query_arg(['action' => self::INSERT, 'orders' => $orders, '_wpnonce' => wp_create_nonce(self::INSERT)], admin_url('admin-post.php'));
    }

    private function stageButton(string $orders, string $stage, string $label, bool $primary, string $title): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::STAGE) . '"><input type="hidden" name="orders" value="' . esc_attr($orders) . '">'
            . '<input type="hidden" name="stage" value="' . esc_attr($stage) . '">'
            . wp_nonce_field(self::STAGE, '_wpnonce', true, false)
            . get_submit_button($label, $primary ? 'primary small' : 'small', 'submit', false, $title === '' ? null : ['title' => $title, 'aria-label' => $title]) . '</form> ';
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
