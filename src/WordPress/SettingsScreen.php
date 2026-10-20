<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

/**
 * The eBay settings: app keys, the URLs he enters at eBay, connect, test, disconnect. Returns its HTML (CD-8).
 * It is the «eBay» tab of the modeller's Configuration page (D-945); `page()` is only the stand-alone fallback for a
 * modeller without tabs.
 */
final class SettingsScreen
{
    /**
     * The tab body. The modeller wraps it in its own form and save button, so the key fields carry no form tag and the
     * actions are nonce links, not nested forms.
     *
     * @param list<array{ok: bool, text: string}> $notices
     */
    public function fields(array $notices): string
    {
        $html = '';

        foreach ($notices as $notice) {
            $html .= '<div class="notice ' . ($notice['ok'] ? 'notice-success' : 'notice-error') . ' inline"><p>'
                . esc_html($notice['text']) . '</p></div>';
        }

        $keys = Settings::appKeys();
        $html .= '<h2>' . esc_html__('1 · App keys', 'taxebay') . '</h2>';

        if (Settings::keysFromConstants() !== null) {
            $html .= '<p>' . esc_html(sprintf(
                /* translators: 1: environment, 2: client id */
                __('Set in wp-config.php (%1$s, App ID %2$s). These take precedence over the fields.', 'taxebay'),
                $keys?->environment->value ?? '',
                $keys?->clientId ?? ''
            )) . '</p>';
        } else {
            $html .= $this->keyFields();
        }

        $html .= '<h2>' . esc_html__('2 · URLs for the RuName at eBay', 'taxebay') . '</h2>'
            . '<table class="form-table" role="presentation"><tbody>'
            . $this->urlRow(__('Auth accepted URL', 'taxebay'), admin_url('admin-post.php?action=taxebay_return'))
            . $this->urlRow(__('Auth declined URL', 'taxebay'), Plugin::settingsUrl())
            . '</tbody></table>';

        $connection = Settings::connection();
        $html .= '<h2>' . esc_html__('3 · Account', 'taxebay') . '</h2>';

        if ($connection === null) {
            $html .= '<p>' . esc_html__('Not connected.', 'taxebay')
                . ($keys === null ? ' ' . esc_html__('Save the keys first.', 'taxebay') : '') . '</p>'
                . ($keys === null ? '' : '<p>' . $this->action('taxebay_connect', __('Connect to eBay', 'taxebay'), true) . '</p>');
        } else {
            $html .= '<p>' . esc_html(sprintf(
                /* translators: 1: eBay user name, 2: date, 3: date the consent runs out */
                __('Connected as %1$s since %2$s. Consent valid until %3$s.', 'taxebay'),
                $connection['username'],
                $connection['connected_at'],
                $connection['refresh_expires'] > 0 ? wp_date('Y-m-d', $connection['refresh_expires']) : '?'
            )) . '</p><p>'
                . $this->action('taxebay_check', __('Test connection', 'taxebay'), true) . ' '
                . $this->action('taxebay_connect', __('Connect again', 'taxebay'), false) . ' '
                . $this->action('taxebay_disconnect', __('Disconnect', 'taxebay'), false) . '</p>';
        }

        return $html;
    }

    /** @param list<array{ok: bool, text: string}> $notices */
    public function page(array $notices): string
    {
        return '<div class="wrap"><h1>' . esc_html__('eBay connection', 'taxebay') . '</h1>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="taxebay_save_keys">' . wp_nonce_field('taxebay_save_keys', '_wpnonce', true, false)
            . $this->fields($notices)
            . get_submit_button(__('Save', 'taxebay'))
            . '</form></div>';
    }

    /** The secret is never sent back to the browser; an empty field keeps the stored one. */
    private function keyFields(): string
    {
        $stored = Settings::storedKeys();
        $hasSecret = $stored['client_secret'] !== '';

        return '<p>' . esc_html__('From your app at developer.ebay.com. The Cert ID is stored encrypted. Alternatively define TAXEBAY_CLIENT_ID, TAXEBAY_CLIENT_SECRET and TAXEBAY_RUNAME in wp-config.php.', 'taxebay') . '</p>'
            . '<table class="form-table" role="presentation"><tbody>'
            . $this->inputRow('client_id', __('App ID (Client ID)', 'taxebay'), 'text', $stored['client_id'], '')
            . $this->inputRow('client_secret', __('Cert ID (Client Secret)', 'taxebay'), 'password', '', $hasSecret ? __('stored — leave empty to keep it', 'taxebay') : '')
            . $this->inputRow('runame', __('RuName', 'taxebay'), 'text', $stored['runame'], '')
            . '<tr><th scope="row">' . esc_html__('Environment', 'taxebay') . '</th><td><label><input type="checkbox" name="taxebay_sandbox" value="1"'
            . checked($stored['sandbox'], true, false) . '> ' . esc_html__('Sandbox keyset', 'taxebay') . '</label></td></tr>'
            . '</tbody></table>';
    }

    private function inputRow(string $name, string $label, string $type, string $value, string $placeholder): string
    {
        return '<tr><th scope="row"><label for="taxebay-' . esc_attr($name) . '">' . esc_html($label) . '</label></th>'
            . '<td><input type="' . esc_attr($type) . '" class="regular-text" autocomplete="off" id="taxebay-' . esc_attr($name)
            . '" name="taxebay_' . esc_attr($name) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '"></td></tr>';
    }

    private function urlRow(string $label, string $url): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td><code>' . esc_html($url) . '</code></td></tr>';
    }

    private function action(string $action, string $label, bool $primary): string
    {
        $url = wp_nonce_url(admin_url('admin-post.php?action=' . $action), $action);

        return '<a class="button' . ($primary ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
}
