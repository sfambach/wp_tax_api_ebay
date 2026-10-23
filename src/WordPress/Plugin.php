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
use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\ShippingClient;

/**
 * The boundary: menu, connect, the OAuth return, the connection test. It wires WordPress to the core and decides nothing.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md
 */
final class Plugin
{
    public const VERSION    = '0.0.35';
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
            try {
                $open = self::shippingClient()->open(self::accessToken(), self::labelledSince());
            } catch (EbayApiException | SecretDoesNotOpen $failure) {
                $open = $failure->getMessage();
            }

            print (new ShippingScreen())->page($open, $this->takeNotices(), self::seller());
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
            'noImage' => __('There is no image in the clipboard.', 'taxebay'),
        ]);
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
                /* translators: %s: eBay order number */
                $this->notice(true, sprintf(__('%s reported as shipped.', 'taxebay'), $sale->orderId));
            }
        } catch (EbayApiException | SecretDoesNotOpen $failure) {
            $this->notice(false, $failure->getMessage());
        }

        wp_safe_redirect(admin_url('admin.php?page=' . ShippingScreen::PAGE));
        exit;
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
        print (new ShippingScreen())->insert($sales, Settings::insertFormat(), self::seller());
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

    /** Labels bought at eBay in the last three days still show, for packing and the insert. */
    private static function labelledSince(): string
    {
        return gmdate('Y-m-d\\TH:i:s.000\\Z', time() - 3 * DAY_IN_SECONDS);
    }

    private static function shippingClient(): ShippingClient
    {
        return new ShippingClient((Settings::appKeys() ?? throw new EbayApiException(__('The app keys are missing.', 'taxebay')))->environment, WpHttpTransport::budgeted());
    }

    private static function accessToken(): string
    {
        $keys = Settings::appKeys() ?? throw new EbayApiException(__('The app keys are missing.', 'taxebay'));
        $connection = Settings::connection() ?? throw new EbayApiException(__('Not connected to eBay yet.', 'taxebay'));

        return (new TokenClient($keys, WpHttpTransport::budgeted()))->refresh(Settings::vault()->open($connection['refresh']), time())->accessToken;
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
