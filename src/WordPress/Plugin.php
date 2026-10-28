<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use DateTimeImmutable;
use SimpleXMLElement;
use Taxmod\Core\Exception\SecretDoesNotOpen;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\OAuth\AppKeys;
use TaxEbay\Core\OAuth\IdentityClient;
use TaxEbay\Core\OAuth\TokenClient;
use TaxEbay\Core\Purchases\PurchaseMapper;
use TaxEbay\Core\Purchases\PurchasesClient;
use TaxEbay\Core\Shipping\DhlLabelLink;
use TaxEbay\Core\Shipping\LabelMatch;
use TaxEbay\Core\Shipping\LabelText;
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\ParcelList;
use TaxEbay\Core\Shipping\Parcels;
use TaxEbay\Core\Shipping\ShippingClient;
use TaxEbay\Core\Shipping\Stages;

/**
 * The boundary: menu, connect, the OAuth return, the connection test. It wires WordPress to the core and decides nothing.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md
 */
final class Plugin
{
    private const LABEL_FETCHES_PER_VIEW = 5;
    /** A buyer's inquiry waits days for an answer; three hours keep the Post-Order calls far below their cap. */
    private const DISPUTE_CHECK_SECONDS = 3 * 3600;

    public const VERSION    = '0.0.63';
    public const CAPABILITY = 'manage_options';
    public const PAGE       = 'taxebay';

    /** The OAuth state of one user's pending consent — it plays the nonce for the return from eBay. */
    private const STATE = 'taxebay_state_';

    /** Messages for the next page view of one user, after a redirect. */
    private const NOTICES = 'taxebay_notices_';

    public static function boot(): void
    {
        $plugin = new self();

        add_action('admin_menu', $plugin->registerMenu(...), 20);
        add_filter('taxmod_config_tabs', $plugin->registerTab(...));
        add_filter('taxmod_import_sources', static fn (array $sources): array => [...$sources, new EbaySource()]);
        add_action('admin_post_taxebay_save_keys', $plugin->handleSaveKeys(...));
        add_action('admin_post_taxebay_connect', $plugin->handleConnect(...));
        add_action('admin_post_taxebay_return', $plugin->handleReturn(...));
        add_action('admin_post_taxebay_check', $plugin->handleCheck(...));
        add_action('rest_api_init', AccountDeletionEndpoint::register(...));
        add_action('admin_post_taxebay_disconnect', $plugin->handleDisconnect(...));
        add_action('admin_post_taxebay_export', $plugin->handleExport(...));
        add_action('admin_menu', $plugin->registerShippingPage(...), 21);
        add_action('admin_post_taxebay_ship', $plugin->handleShip(...));
        add_action('admin_post_taxebay_insert', $plugin->handleInsert(...));
        add_action('admin_post_taxebay_stage', $plugin->handleStage(...));

        foreach (Stages::ALL as $stage) {
            add_action('admin_post_' . ShippingScreen::BULK_TO . $stage, $plugin->handleBulkStage(...));
        }

        add_action('admin_post_taxebay_label_pdf', $plugin->handleLabelPdf(...));
        add_action('admin_post_' . ShippingScreen::DHL_REFRESH, $plugin->handleDhlRefresh(...));
    }

    /**
     * The «eBay» tab of the modeller's Configuration page (D-945); the core checks capability and nonce before save.
     *
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public function registerTab(array $tabs): array
    {
        $tabs['ebay'] = [
            'title'  => __('eBay', 'taxebay'),
            'render' => fn (): string => (new SettingsScreen())->fields($this->takeNotices()),
            'save'   => static fn (array $post): bool => self::saveKeys($post),
            'guide'  => static fn (): string => (new SettingsScreen())->guide(),
            'version' => self::VERSION,
        ];

        // The shared layout of D-981: the modeller draws access, fetch interval and settings in that order.
        if (SettingsScreen::sharedLayout()) {
            $tabs['ebay'] = [
                ...$tabs['ebay'],
                'credentials' => fn (): string => (new SettingsScreen())->credentials($this->takeNotices()),
                'test'        => static fn (): string => self::testConnection(),
                'forget'      => static function (): void {
                    Settings::forget();
                    Settings::forgetKeys();
                },
                'source'      => 'ebay',
                'render'      => static fn (): string => (new SettingsScreen())->settings(),
            ];
        }

        return $tabs;
    }

    /** Only for a modeller without the tabs of D-945: then the module keeps its own page. */
    public function registerMenu(): void
    {
        if (self::hasConfigTabs()) {
            return;
        }

        $parent = isset($GLOBALS['admin_page_hooks']['taxmod']) ? 'taxmod' : 'options-general.php';

        add_submenu_page(
            $parent,
            __('eBay connection', 'taxebay'),
            __('eBay', 'taxebay'),
            self::CAPABILITY,
            self::PAGE,
            function (): void {
                print (new SettingsScreen())->page($this->takeNotices());
            }
        );
    }

    public static function settingsUrl(): string
    {
        return self::hasConfigTabs()
            ? admin_url('admin.php?page=taxmod-settings&tab=ebay')
            : admin_url('admin.php?page=' . self::PAGE);
    }

    private static function hasConfigTabs(): bool
    {
        return defined('Taxmod\WordPress\Admin\SettingsScreen::TABS_FILTER');
    }

    public function handleConnect(): void
    {
        $keys = $this->keysOrBack('taxebay_connect');
        $state = wp_generate_password(32, false);
        set_transient(self::STATE . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS);

        // eBay's own host, which wp_safe_redirect would refuse.
        wp_redirect((new TokenClient($keys, WpHttpTransport::budgeted()))->consentUrl($state));
        exit;
    }

    /** eBay sends him back here with `code` and `state`; the state stands in for the nonce (CD-5). */
    public function handleReturn(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You may not change the eBay connection.', 'taxebay'), 403);
        }

        $key = self::STATE . get_current_user_id();
        $expected = (string) get_transient($key);
        delete_transient($key);
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        if ($expected === '' || ! hash_equals($expected, $state)) {
            wp_die(esc_html__('This answer from eBay does not belong to a connection you started. Please connect again.', 'taxebay'), 403);
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $keys = Settings::appKeys();

        if ($code === '' || $keys === null) {
            $this->notice(false, __('eBay sent no consent code — the connection was declined or cancelled.', 'taxebay'));
            $this->back();
        }

        try {
            $http = WpHttpTransport::budgeted();
            $tokens = (new TokenClient($keys, $http))->exchangeCode($code, time());
            $username = (new IdentityClient($keys->environment, $http))->username($tokens->accessToken);
            Settings::saveConnection(Settings::vault()->seal((string) $tokens->refreshToken), (int) $tokens->refreshExpiresAt, $username);
            /* translators: %s: eBay user name */
            $this->notice(true, sprintf(__('Connected as %s.', 'taxebay'), $username));
        } catch (EbayApiException $failure) {
            /* translators: %s: eBay's answer */
            $this->notice(false, sprintf(__('Not connected: %s', 'taxebay'), $failure->getMessage()));
        }

        $this->back();
    }

    public function handleCheck(): void
    {
        $this->keysOrBack('taxebay_check');
        $result = self::testConnection();
        $this->notice(str_starts_with($result, '✓'), $result);
        $this->back();
    }

    /** One sentence for the modeller's «Test connection» (D-981) and the old link: who eBay says this site is. */
    public static function testConnection(): string
    {
        $keys = Settings::appKeys();
        $connection = Settings::connection();

        if ($keys === null || $connection === null) {
            return __('Not connected yet: save the keys, then «Connect to eBay».', 'taxebay');
        }

        try {
            $http = WpHttpTransport::budgeted();
            $tokens = (new TokenClient($keys, $http))->refresh(Settings::vault()->open($connection['refresh']), time());

            /* translators: %s: eBay user name */
            return sprintf(__('✓ Connection works: eBay knows this site as %s.', 'taxebay'), (new IdentityClient($keys->environment, $http))->username($tokens->accessToken));
        } catch (EbayApiException | SecretDoesNotOpen $failure) {
            /* translators: %s: reason */
            return sprintf(__('Connection test failed: %s', 'taxebay'), $failure->getMessage());
        }
    }

    /**
     * His purchases of the last 90 days as eBay delivers them, as a JSON file — to compare them with what the model
     * holds from the mails. Reads only; nothing reaches the inbox.
     */
    public function handleExport(): void
    {
        $keys = $this->keysOrBack('taxebay_export');
        $connection = Settings::connection();

        if ($connection === null) {
            $this->notice(false, __('Not connected yet.', 'taxebay'));
            $this->back();
        }

        try {
            $http = WpHttpTransport::budgeted();
            $token = (new TokenClient($keys, $http))->refresh(Settings::vault()->open($connection['refresh']), time())->accessToken;
            $now = new DateTimeImmutable('now');
            $orders = (new PurchasesClient($keys->environment, $http))
                ->ordersCreatedBetween($token, $now->modify('-' . (PurchasesClient::MAX_DAYS_BACK - 1) . ' days'), $now);
        } catch (EbayApiException | SecretDoesNotOpen $failure) {
            /* translators: %s: reason */
            $this->notice(false, sprintf(__('Export failed: %s', 'taxebay'), $failure->getMessage()));
            $this->back();
        }

        $mapper = new PurchaseMapper();
        $rows = array_map(static fn (SimpleXMLElement $order): array => [
            ...$mapper->map($order)->toArray(),
            'order_numbers' => $mapper->orderNumbers($order),
            'order_status'  => (string) $order->OrderStatus,
            'paid_at'       => (string) ($order->PaidTime ?? ''),
        ], $orders);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="ebay-purchases-' . gmdate('Y-m-d') . '.json"');
        echo wp_json_encode(['exported_at' => $now->format(DATE_ATOM), 'orders' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** «eBay shipping» under the modeller's menu (D-976). */
    public function registerShippingPage(): void
    {
        $parent = isset($GLOBALS['admin_page_hooks']['taxmod']) ? 'taxmod' : 'tools.php';

        $hook = add_submenu_page($parent, __('eBay shipping', 'taxebay'), __('eBay shipping', 'taxebay'), self::CAPABILITY, ShippingScreen::PAGE, function (): void {
            $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : Stages::OPEN;
            $orderBy = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'date';
            $list = new ParcelList(
                isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
                isset($_GET['label']) ? sanitize_key(wp_unslash($_GET['label'])) : '',
                isset($_GET['paid']) ? sanitize_key(wp_unslash($_GET['paid'])) : '',
                in_array($orderBy, ParcelList::ORDER_BY, true) ? $orderBy : 'date',
                isset($_GET['order']) && sanitize_key(wp_unslash($_GET['order'])) === 'desc',
            );
            $stages = Settings::stages();
            $carrier = [];
            $disputes = [];

            try {
                $open = self::shownWithTracking(self::accessToken(), $tab, $stages, $list, $carrier, $disputes);
            } catch (EbayApiException | SecretDoesNotOpen $failure) {
                $open = $failure->getMessage();
            }

            $labels = self::dhlLabels();

            print (new ShippingScreen())->page($open, $this->takeNotices(), self::seller(), $stages, $tab, time(), $list, $labels, $carrier, is_array($open) ? self::labelsByOrder($open, $labels) : [], $disputes);
        });

        if ($hook !== false) {
            add_action('load-' . $hook, self::enqueueLabelPrint(...));
        }
    }

    /** pdf.js ships with the plugin, so the label PDF is read in the browser and never uploaded. */
    private static function enqueueLabelPrint(): void
    {
        $main = dirname(__DIR__, 2) . '/wp-tax-api-ebay.php';
        wp_enqueue_script('taxebay-pdfjs', plugins_url('assets/pdfjs/pdf.min.js', $main), [], '3.11.174', true);
        wp_enqueue_script('taxebay-label62', plugins_url('assets/label62.js', $main), ['taxebay-pdfjs'], self::VERSION, true);
        wp_localize_script('taxebay-label62', 'taxebayLabel', [
            'worker' => plugins_url('assets/pdfjs/pdf.worker.min.js', $main),
            // eBay's label PDF uses fonts it does not embed; without these pdf.js leaves that text out.
            'cmaps'  => plugins_url('assets/pdfjs/cmaps/', $main),
            'fonts'  => plugins_url('assets/pdfjs/standard_fonts/', $main),
            'empty'  => __('The PDF has no printed content.', 'taxebay'),
            'failed' => __('The label could not be read:', 'taxebay'),
            'noLink'  => __('Paste the «print label» link from DHL\'s mail first.', 'taxebay'),
            // Not wp_nonce_url(): that escapes & for HTML, and this goes to a script.
            'fetch'   => add_query_arg(['action' => ShippingScreen::LABEL_PDF, '_wpnonce' => wp_create_nonce(ShippingScreen::LABEL_PDF)], admin_url('admin-post.php')),
        ]);
    }

    /**
     * Label links tax_mail read from DHL's mails (tax_mail ≥ 0.3.0); only links this plugin would fetch are kept.
     *
     * @return list<array{cart: string, date: string, links: list<string>}>
     */
    private static function dhlLabels(): array
    {
        $labels = [];

        foreach ((array) apply_filters('taxmail_dhl_labels', []) as $label) {
            $links = array_values(array_filter(array_map('strval', (array) ($label['links'] ?? [])), DhlLabelLink::accepts(...)));

            if ($links !== []) {
                $labels[] = ['cart' => (string) ($label['cart'] ?? ''), 'date' => (string) ($label['date'] ?? ''), 'links' => $links];
            }
        }

        return $labels;
    }

    /** The eBay user name his account is connected as. */
    private static function seller(): string
    {
        return Settings::connection()['username'] ?? '';
    }

    /** One tracking number for every sale of the parcel (Parcels). */
    public function handleShip(): void
    {
        $this->guard(ShippingScreen::SHIP);
        $wanted = self::orderIds(isset($_POST['orders']) ? sanitize_text_field(wp_unslash($_POST['orders'])) : '');
        $carrier = strtoupper(isset($_POST['carrier']) ? sanitize_key(wp_unslash($_POST['carrier'])) : '');
        $tracking = (string) preg_replace('/[^A-Za-z0-9]/', '', isset($_POST['tracking']) ? sanitize_text_field(wp_unslash($_POST['tracking'])) : '');

        try {
            $sales = self::openSales($wanted);

            if ($tracking === '' || $sales === []) {
                throw new EbayApiException(__('Order not open any more, or no tracking number.', 'taxebay'));
            }

            $token = self::accessToken();
            $client = self::shippingClient();

            foreach ($sales as $sale) {
                $client->markShipped($token, $sale->orderId, $sale->items, $carrier, $tracking, gmdate('Y-m-d\TH:i:s.000\Z'));
                Settings::saveStage([$sale->orderId], Stages::SHIPPED, time());
                /* translators: %s: eBay order number */
                $this->notice(true, sprintf(__('%s reported as shipped.', 'taxebay'), $sale->orderId));
            }
        } catch (EbayApiException | SecretDoesNotOpen $failure) {
            $this->notice(false, $failure->getMessage());
        }

        wp_safe_redirect(admin_url('admin.php?page=' . ShippingScreen::PAGE));
        exit;
    }

    /** Ticked rows move to the tab the bulk action names (ListPage's bulk bar posts the handler as its action). */
    public function handleBulkStage(): void
    {
        $this->guard(ShippingScreen::STAGE);
        $selected = isset($_POST['selected']) ? (array) wp_unslash($_POST['selected']) : [];
        $this->moveTo(
            substr(current_action(), strlen('admin_post_' . ShippingScreen::BULK_TO)),
            self::orderIds(implode(',', array_map(static fn ($one): string => sanitize_text_field((string) $one), $selected)))
        );
    }

    /** A parcel moves one tab on or back (D-985); eBay knows nothing of it, it is his note only. */
    public function handleStage(): void
    {
        $this->guard(ShippingScreen::STAGE);
        $this->moveTo(
            isset($_POST['stage']) ? sanitize_key(wp_unslash($_POST['stage'])) : '',
            self::orderIds(isset($_POST['orders']) ? sanitize_text_field(wp_unslash($_POST['orders'])) : '')
        );
    }

    /** @param list<string> $orders */
    private function moveTo(string $stage, array $orders): void
    {
        if (! in_array($stage, Stages::ALL, true) || $orders === []) {
            $this->notice(false, __('Pick an action and at least one parcel.', 'taxebay'));
        } else {
            if ($stage === Stages::SHIPPED || $stage === Stages::RECEIVED) {
                $orders = $this->onlyLabelled($orders);
            }

            Settings::saveStage($orders, $stage, time());
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=' . ShippingScreen::PAGE));
        exit;
    }

    /**
     * Without an eBay label, eBay learns of the parcel only from «Mark shipped»; a bulk move past it would skip that.
     *
     * @param list<string> $orderIds
     * @return list<string>
     */
    private function onlyLabelled(array $orderIds): array
    {
        try {
            $sales = self::openSales($orderIds);
        } catch (EbayApiException | SecretDoesNotOpen $failure) {
            $this->notice(false, $failure->getMessage());

            return [];
        }

        $stages = Settings::stages();
        $kept = array_values(array_map(static fn (OpenShipment $sale): string => $sale->orderId, array_filter(
            $sales,
            static fn (OpenShipment $sale): bool => $sale->labelled || in_array($stages[$sale->orderId]['stage'] ?? '', [Stages::SHIPPED, Stages::RECEIVED], true)
        )));
        $skipped = array_diff($orderIds, $kept);

        if ($skipped !== []) {
            /* translators: %s: eBay order numbers */
            $this->notice(false, sprintf(__('%s skipped: no eBay label — report the tracking number with «Mark shipped» first.', 'taxebay'), implode(', ', $skipped)));
        }

        return $kept;
    }

    /**
     * The label PDF behind the link in DHL's mail, fetched here because the browser may not read another site's
     * answer. Only that one DHL address is fetched (DhlLabelLink); the PDF is passed through, not stored.
     */
    public function handleLabelPdf(): void
    {
        $this->guard(ShippingScreen::LABEL_PDF);
        $url = isset($_GET['url']) ? esc_url_raw(wp_unslash($_GET['url']), ['https']) : '';

        if (! DhlLabelLink::accepts($url)) {
            wp_die(esc_html__('That is not a DHL label link.', 'taxebay'), 400);
        }

        $body = self::labelPdf($url);

        if ($body === null) {
            wp_die(esc_html__('DHL did not hand out the label — the link may have expired.', 'taxebay'), 502);
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        print $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a PDF, not HTML
        exit;
    }

    /** The PDF behind a DHL label link, or null when DHL does not hand it out; callers check the link first. */
    private static function labelPdf(string $url): ?string
    {
        $response = wp_remote_get($url, ['timeout' => 20, 'redirection' => 0]);
        $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);

        return wp_remote_retrieve_response_code($response) === 200 && str_starts_with($body, '%PDF') ? $body : null;
    }

    /**
     * Which DHL label belongs to which shown parcel, read from the recipient printed on each label. A label's text is
     * kept for the 30 days tax_mail keeps the link, so each PDF is fetched once.
     *
     * @param list<OpenShipment> $sales
     * @param list<array{cart: string, date: string, links: list<string>}> $labels
     * @return array<string, string> order id → label link
     */
    private static function labelsByOrder(array $sales, array $labels): array
    {
        $parcels = array_values(array_filter(Parcels::of($sales), static fn (array $parcel): bool => $parcel[0]->labelled));

        if ($parcels === [] || $labels === []) {
            return [];
        }

        $byOrder = [];
        $fetched = 0;

        foreach ($labels as $label) {
            foreach ($label['links'] as $link) {
                $key = 'taxebay_label_' . md5($link);
                $lines = get_transient($key);

                if (! is_array($lines)) {
                    // A slow page beats a stuck one: at most a few new PDFs per view, the rest next time.
                    if (++$fetched > self::LABEL_FETCHES_PER_VIEW) {
                        continue;
                    }

                    $pdf = self::labelPdf($link);
                    $lines = $pdf === null ? [] : LabelText::of($pdf);
                    set_transient($key, $lines, $pdf === null ? HOUR_IN_SECONDS : 30 * DAY_IN_SECONDS);
                }

                foreach ($parcels as $parcel) {
                    if (LabelMatch::fits(array_map('strval', $lines), $parcel)) {
                        foreach ($parcel as $sale) {
                            $byOrder[$sale->orderId] ??= $link;
                        }
                    }
                }
            }
        }

        return $byOrder;
    }

    public function handleInsert(): void
    {
        $this->guard(ShippingScreen::INSERT);

        try {
            $sales = self::openSales(self::orderIds(isset($_GET['orders']) ? sanitize_text_field(wp_unslash($_GET['orders'])) : ''));
        } catch (EbayApiException | SecretDoesNotOpen $failure) {
            wp_die(esc_html($failure->getMessage()));
        }

        if ($sales === []) {
            wp_die(esc_html__('Order not open any more.', 'taxebay'));
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        $project = isset($_GET['github']) ? esc_url_raw(wp_unslash($_GET['github']), ['https']) : '';
        print (new ShippingScreen())->insert($sales, Settings::insertFormat(), self::seller(), $project);
        exit;
    }

    /** @return list<string> */
    private static function orderIds(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $id): bool => $id !== ''));
    }

    /**
     * The open sales among these ids; one read for all (CD-7).
     *
     * @param list<string> $orderIds
     * @return list<OpenShipment>
     */
    private static function openSales(array $orderIds): array
    {
        return array_values(array_filter(self::shippingClient()->open(self::accessToken(), self::labelledSince()), static fn (OpenShipment $sale): bool => in_array($sale->orderId, $orderIds, true)));
    }

    /**
     * @param list<OpenShipment> $sales
     * @param array<string, array<string, mixed>> $carrier
     */
    private static function lastCarrierWord(array $sales, array &$carrier): void
    {
        foreach ($sales as $sale) {
            $number = trim(explode(',', $sale->tracking)[0]);
            $word = $number === '' ? null : apply_filters('taxmod_dhl_tracking', null, $number);

            if (is_array($word) && isset($word['stage'])) {
                $carrier[$sale->orderId] = $word;
            }
        }
    }

    /**
     * Asks eBay for open buyer inquiries and cases on the refreshed orders and keeps the answer for the page.
     *
     * @param list<string> $orderIds
     */
    private function refreshDisputes(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        $found = [];
        $unread = 0;

        try {
            $token = self::accessToken();
            $client = self::shippingClient();

            foreach ($orderIds as $orderId) {
                $open = $client->disputes($token, $orderId);

                if ($open === null) {
                    ++$unread;
                } else {
                    $found[$orderId] = $open;
                }
            }
        } catch (EbayApiException | SecretDoesNotOpen $failure) {
            $this->notice(false, __('eBay buyer inquiries could not be read:', 'taxebay') . ' ' . $failure->getMessage());
        }

        Settings::saveDisputes($found, time());

        if ($unread > 0) {
            $this->notice(false, sprintf(
                /* translators: %d: number of orders */
                _n('eBay did not answer about inquiries for %d order; the last state stays.', 'eBay did not answer about inquiries for %d orders; the last state stays.', $unread, 'taxebay'),
                $unread
            ));
        }
    }

    /**
     * The scheduled check of the inbox fetch: open buyer inquiries and cases of the parcels in «shipped», each at most
     * every DISPUTE_CHECK_SECONDS, so the page has them without a click (his expectation, 2026-10-27). Never throws —
     * the purchases and sales of the same run must not fail over it.
     */
    public static function checkDisputes(string $token): void
    {
        try {
            $client = self::shippingClient();
            $stages = Settings::stages();
            $noted = Settings::disputes();
            $found = [];

            foreach (Parcels::of($client->open($token, self::labelledSince())) as $parcel) {
                if (Stages::of($parcel, $stages, time()) !== Stages::SHIPPED) {
                    continue;
                }

                foreach ($parcel as $sale) {
                    if ((int) ($noted[$sale->orderId]['at'] ?? 0) > time() - self::DISPUTE_CHECK_SECONDS) {
                        continue;
                    }

                    $open = $client->disputes($token, $sale->orderId);

                    if ($open !== null) {
                        $found[$sale->orderId] = $open;
                    }
                }
            }

            Settings::saveDisputes($found, time());
        } catch (EbayApiException) {
            return;
        }
    }

    /**
     * Asks DHL anew for the given parcels and moves them on: a scan takes «packed» to «shipped», delivery takes «shipped»
     * to «received» — only those two stages ask DHL (his rule, 2026-10-27).
     */
    public function handleDhlRefresh(): void
    {
        $this->guard(ShippingScreen::DHL_REFRESH);
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $stages = Settings::stages();
        $list = isset($_GET['parcels']) ? sanitize_text_field(wp_unslash($_GET['parcels'])) : '';
        $failed = [];

        $asked = [];

        foreach (array_slice(explode(';', $list), 0, ShippingScreen::REFRESH_AT_ONCE) as $parcel) {
            if (preg_match('/^([0-9A-Za-z-]+(?:,[0-9A-Za-z-]+)*):([0-9A-Za-z]{6,40})$/', $parcel, $parts) !== 1) {
                continue;
            }

            array_push($asked, ...explode(',', $parts[1]));

            $word = apply_filters('taxmod_dhl_tracking', null, $parts[2], 'refresh');

            if (! is_array($word) || ! isset($word['stage'])) {
                $failed[] = $parts[2];

                continue;
            }

            foreach (explode(',', $parts[1]) as $orderId) {
                $current = $stages[$orderId]['stage'] ?? $tab;

                if (! in_array($current, [Stages::PACKED, Stages::SHIPPED], true)) {
                    continue;
                }

                $target = Stages::afterCarrier($current, (string) $word['stage']);

                if ($target !== null) {
                    Settings::saveStage([$orderId], $target, time());
                }
            }
        }

        $this->refreshDisputes($asked);

        // No answer keeps the last one; say so instead of leaving him to wonder (2026-10-27).
        if ($failed !== []) {
            $this->notice(false, sprintf(
                /* translators: %s: tracking numbers */
                __('DHL gave no answer for %s (no key, daily budget used up or a DHL error); the last state stays.', 'taxebay'),
                implode(', ', $failed)
            ));
        }

        $open = isset($_GET[ShippingScreen::OPEN_HISTORY]) ? sanitize_text_field(wp_unslash($_GET[ShippingScreen::OPEN_HISTORY])) : '';
        $back = remove_query_arg(ShippingScreen::OPEN_HISTORY, wp_get_referer() ?: admin_url('admin.php?page=' . ShippingScreen::PAGE));
        wp_safe_redirect($open === '' ? $back : add_query_arg(ShippingScreen::OPEN_HISTORY, rawurlencode($open), $back));
        exit;
    }

    /** Orders eBay counts as shipped stay readable as long as his stage notes last (D-985). */
    private static function labelledSince(): string
    {
        return gmdate('Y-m-d\\TH:i:s.000\\Z', time() - Settings::STAGE_DAYS * DAY_IN_SECONDS);
    }

    /**
     * All sales for the tab counts; tracking numbers only for the parcels of the tab he looks at — one call each.
     *
     * @param array<string, array{stage: string, at: int}> $stages updated where the carrier moved a parcel on
     * @param array<string, array<string, mixed>> $carrier order id → the DHL module's word on it; `mixed` is its array
     * @param array<string, list<array{kind: string, id: string, status: string, respond_by: string}>> $disputes order id → open inquiries and cases
     * @return list<OpenShipment>
     */
    private static function shownWithTracking(string $token, string $tab, array &$stages, ParcelList $list, array &$carrier, array &$disputes = []): array
    {
        $client = self::shippingClient();
        $open = $client->open($token, self::labelledSince());
        $shown = [];
        $inTab = array_values(array_filter(Parcels::of($open), static fn (array $parcel): bool => Stages::of($parcel, $stages, time()) === $tab));

        // The search reads tracking numbers too, so filters other than the search decide which parcels get them.
        foreach ((new ParcelList('', $list->label, $list->paid))->apply($inTab) as $parcel) {
            foreach ($parcel as $sale) {
                $shown[$sale->orderId] = true;
            }
        }

        // A tracking number does not change once the label is bought: eBay is asked only for those not known yet.
        $known = Settings::trackingNumbers();
        $toShow = array_values(array_filter($open, static fn (OpenShipment $sale): bool => isset($shown[$sale->orderId])));
        $tracked = array_merge(
            array_map(static fn (OpenShipment $sale): OpenShipment => $sale->withTracking($known[$sale->orderId]), array_values(array_filter($toShow, static fn (OpenShipment $sale): bool => $sale->labelled && ($known[$sale->orderId] ?? '') !== ''))),
            $client->tracked($token, array_values(array_filter($toShow, static fn (OpenShipment $sale): bool => ! $sale->labelled || ($known[$sale->orderId] ?? '') === '')))
        );
        Settings::saveTrackingNumbers(array_filter(array_combine(
            array_map(static fn (OpenShipment $sale): string => $sale->orderId, $tracked),
            array_map(static fn (OpenShipment $sale): string => $sale->tracking, $tracked)
        ) ?: [], static fn (string $number): bool => $number !== ''), time());
        $byId = array_combine(array_map(static fn (OpenShipment $sale): string => $sale->orderId, $tracked), $tracked);
        // Only what DHL said last; asking anew is a click away (his wish, 2026-10-27), so the page loads fast.
        self::lastCarrierWord($tracked, $carrier);

        // What eBay said at the last refresh; asking anew is the refresh's job (his rule, 2026-10-27).
        $stored = Settings::disputes();

        foreach ($tracked as $sale) {
            if (($stored[$sale->orderId]['open'] ?? []) !== []) {
                $disputes[$sale->orderId] = $stored[$sale->orderId]['open'];
            }
        }

        return array_map(static fn (OpenShipment $sale): OpenShipment => $byId[$sale->orderId] ?? $sale, $open);
    }

    private static function shippingClient(): ShippingClient
    {
        return new ShippingClient((Settings::appKeys() ?? throw new EbayApiException(__('The app keys are missing.', 'taxebay')))->environment, WpHttpTransport::budgeted());
    }

    private static function accessToken(): string
    {
        $keys = Settings::appKeys() ?? throw new EbayApiException(__('The app keys are missing.', 'taxebay'));
        $connection = Settings::connection() ?? throw new EbayApiException(__('Not connected to eBay yet.', 'taxebay'));

        $kept = get_transient(Settings::ACCESS_TOKEN);

        // Sealed like every secret of the module (D-951); kept for its own lifetime less a margin, so a page view
        // does not start with a call to eBay.
        if (is_string($kept) && $kept !== '') {
            try {
                return Settings::vault()->open($kept);
            } catch (SecretDoesNotOpen) {
                delete_transient(Settings::ACCESS_TOKEN);
            }
        }

        $tokens = (new TokenClient($keys, WpHttpTransport::budgeted()))->refresh(Settings::vault()->open($connection['refresh']), time());
        $lifetime = $tokens->accessExpiresAt - time() - 300;

        if ($lifetime > 0) {
            set_transient(Settings::ACCESS_TOKEN, Settings::vault()->seal($tokens->accessToken), $lifetime);
        }

        return $tokens->accessToken;
    }

    /** The fallback page's own form; the tab saves through the modeller. */
    public function handleSaveKeys(): void
    {
        $this->guard('taxebay_save_keys');
        $this->notice(true, self::saveKeys(wp_unslash($_POST)) ? __('Keys saved.', 'taxebay') : __('Saved, but the keys are incomplete.', 'taxebay'));
        $this->back();
    }

    /**
     * Validates and sanitizes the key fields; capability and nonce are checked by the caller.
     *
     * @param array<string, mixed> $post already unslashed
     * @return bool whether the keys are complete now
     */
    private static function saveKeys(array $post): bool
    {
        $field = static fn (string $name): string => is_string($post[$name] ?? null) ? trim(sanitize_text_field($post[$name])) : '';
        if (isset($post['taxebay_insert_format'])) {
            Settings::saveInsertFormat(sanitize_key($field('taxebay_insert_format')));
        }

        // Under the shared layout the modeller stores the interval; an absent field must not switch fetching off.
        if (isset($post['taxebay_schedule'])) {
            Settings::saveSchedule(sanitize_key($field('taxebay_schedule')));
        }

        if (Settings::keysFromConstants() !== null) {
            return true;
        }

        Settings::saveKeys($field('taxebay_client_id'), $field('taxebay_client_secret'), $field('taxebay_runame'), isset($post['taxebay_sandbox']));

        return Settings::appKeys() !== null;
    }

    /** Forgets the token here; the consent at eBay stays until he revokes it in his eBay account. */
    public function handleDisconnect(): void
    {
        $this->guard('taxebay_disconnect');
        Settings::forget();
        $this->notice(true, __('Disconnected. To revoke the consent at eBay as well, remove the app in your eBay account settings.', 'taxebay'));
        $this->back();
    }

    /** CD-5, in this order: capability, then nonce. */
    private function guard(string $action): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You may not change the eBay connection.', 'taxebay'), 403);
        }

        check_admin_referer($action);
    }

    private function keysOrBack(string $action): AppKeys
    {
        $this->guard($action);
        $keys = Settings::appKeys();

        if ($keys === null) {
            $this->notice(false, __('The app keys are missing — enter and save them first, or define them in wp-config.php.', 'taxebay'));
            $this->back();
        }

        return $keys;
    }

    private function back(): never
    {
        wp_safe_redirect(self::settingsUrl());
        exit;
    }

    private function notice(bool $ok, string $text): void
    {
        $key = self::NOTICES . get_current_user_id();
        $notices = get_transient($key);
        $notices = is_array($notices) ? $notices : [];
        $notices[] = ['ok' => $ok, 'text' => $text];
        set_transient($key, $notices, 10 * MINUTE_IN_SECONDS);
    }

    /** @return list<array{ok: bool, text: string}> */
    private function takeNotices(): array
    {
        $key = self::NOTICES . get_current_user_id();
        $notices = get_transient($key);
        delete_transient($key);

        return is_array($notices)
            ? array_values(array_map(static fn (mixed $n): array => ['ok' => (bool) ($n['ok'] ?? false), 'text' => (string) ($n['text'] ?? '')], $notices))
            : [];
    }
}
