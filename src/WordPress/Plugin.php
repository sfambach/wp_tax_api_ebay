<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use RuntimeException;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\OAuth\AppKeys;
use TaxEbay\Core\OAuth\IdentityClient;
use TaxEbay\Core\OAuth\TokenClient;

/**
 * The boundary: menu, connect, the OAuth return, the connection test. It wires WordPress to the core and decides nothing.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md
 */
final class Plugin
{
    public const VERSION    = '0.0.3';
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
        add_action('admin_post_taxebay_save_keys', $plugin->handleSaveKeys(...));
        add_action('admin_post_taxebay_connect', $plugin->handleConnect(...));
        add_action('admin_post_taxebay_return', $plugin->handleReturn(...));
        add_action('admin_post_taxebay_check', $plugin->handleCheck(...));
        add_action('admin_post_taxebay_disconnect', $plugin->handleDisconnect(...));
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
        ];

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
        if (Settings::keysFromConstants() !== null) {
            return true;
        }

        $field = static fn (string $name): string => is_string($post[$name] ?? null) ? trim(sanitize_text_field($post[$name])) : '';
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
