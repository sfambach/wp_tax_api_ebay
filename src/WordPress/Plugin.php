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
    public const VERSION    = '0.0.1';
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
        add_action('admin_post_taxebay_connect', $plugin->handleConnect(...));
        add_action('admin_post_taxebay_return', $plugin->handleReturn(...));
        add_action('admin_post_taxebay_check', $plugin->handleCheck(...));
        add_action('admin_post_taxebay_disconnect', $plugin->handleDisconnect(...));
    }

    public function registerMenu(): void
    {
        // Under the modeller's menu when it is there, like the other modules.
        $parent = isset($GLOBALS['admin_page_hooks']['taxmod']) ? 'taxmod' : 'options-general.php';

        add_submenu_page(
            $parent,
            __('eBay connection', 'taxebay'),
            __('eBay', 'taxebay'),
            self::CAPABILITY,
            self::PAGE,
            function (): void {
                print (new SettingsScreen())->render($this->takeNotices());
            }
        );
    }

    public function handleConnect(): void
    {
        $keys = $this->keysOrBack('taxebay_connect');
        $state = wp_generate_password(32, false);
        set_transient(self::STATE . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS);

        // eBay's own host, which wp_safe_redirect would refuse.
        wp_redirect((new TokenClient($keys, new WpHttpTransport()))->consentUrl($state));
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
            $http = new WpHttpTransport();
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
        $keys = $this->keysOrBack('taxebay_check');
        $connection = Settings::connection();

        if ($connection === null) {
            $this->notice(false, __('Not connected yet.', 'taxebay'));
            $this->back();
        }

        try {
            $http = new WpHttpTransport();
            $tokens = (new TokenClient($keys, $http))->refresh(Settings::vault()->open($connection['refresh']), time());
            $username = (new IdentityClient($keys->environment, $http))->username($tokens->accessToken);
            /* translators: %s: eBay user name */
            $this->notice(true, sprintf(__('Connection works: eBay knows this site as %s.', 'taxebay'), $username));
        } catch (EbayApiException | RuntimeException $failure) {
            /* translators: %s: reason */
            $this->notice(false, sprintf(__('Connection test failed: %s', 'taxebay'), $failure->getMessage()));
        }

        $this->back();
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
            $this->notice(false, __('The app keys are missing in wp-config.php.', 'taxebay'));
            $this->back();
        }

        return $keys;
    }

    private function back(): never
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
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
