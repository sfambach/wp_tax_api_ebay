<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

/**
 * The settings page: what is configured, the URLs he enters at eBay, connect, test, disconnect. Returns its HTML (CD-8).
 */
final class SettingsScreen
{
    /** @param list<array{ok: bool, text: string}> $notices */
    public function render(array $notices): string
    {
        $html = '<div class="wrap"><h1>' . esc_html__('eBay connection', 'taxebay') . '</h1>';

        foreach ($notices as $notice) {
            $html .= '<div class="notice ' . ($notice['ok'] ? 'notice-success' : 'notice-error') . '"><p>'
                . esc_html($notice['text']) . '</p></div>';
        }

        if (! class_exists('Taxmod\\WordPress\\Plugin')) {
            $html .= '<div class="notice notice-warning"><p>' . esc_html__('The Taxonomy Modeller is not active — nothing can be written.', 'taxebay') . '</p></div>';
        }

        $keys = Settings::appKeys();
        $html .= '<h2>' . esc_html__('1 · App keys', 'taxebay') . '</h2>';

        if ($keys === null) {
            $html .= '<p>' . esc_html__('Not set. Create an app at developer.ebay.com and add its keys to wp-config.php:', 'taxebay') . '</p>'
                . '<pre>' . esc_html("define('TAXEBAY_CLIENT_ID', '…');\ndefine('TAXEBAY_CLIENT_SECRET', '…');\ndefine('TAXEBAY_RUNAME', '…');\n// define('TAXEBAY_SANDBOX', true);") . '</pre>';
        } else {
            $html .= '<p>' . esc_html(sprintf(
                /* translators: 1: environment, 2: client id */
                __('Set (%1$s, App ID %2$s).', 'taxebay'),
                $keys->environment->value,
                $keys->clientId
            )) . '</p>';
        }

        $html .= '<h2>' . esc_html__('2 · URLs for the RuName at eBay', 'taxebay') . '</h2>'
            . '<table class="form-table" role="presentation"><tbody>'
            . $this->urlRow(__('Auth accepted URL', 'taxebay'), admin_url('admin-post.php?action=taxebay_return'))
            . $this->urlRow(__('Auth declined URL', 'taxebay'), admin_url('admin.php?page=' . Plugin::PAGE))
            . '</tbody></table>';

        $connection = Settings::connection();
        $html .= '<h2>' . esc_html__('3 · Account', 'taxebay') . '</h2>';

        if ($connection === null) {
            $html .= '<p>' . esc_html__('Not connected.', 'taxebay') . '</p>'
                . ($keys === null ? '' : $this->button('taxebay_connect', __('Connect to eBay', 'taxebay'), true));
        } else {
            $html .= '<p>' . esc_html(sprintf(
                /* translators: 1: eBay user name, 2: date, 3: date the consent runs out */
                __('Connected as %1$s since %2$s. Consent valid until %3$s.', 'taxebay'),
                $connection['username'],
                $connection['connected_at'],
                $connection['refresh_expires'] > 0 ? wp_date('Y-m-d', $connection['refresh_expires']) : '?'
            )) . '</p>'
                . $this->button('taxebay_check', __('Test connection', 'taxebay'), true)
                . $this->button('taxebay_connect', __('Connect again', 'taxebay'), false)
                . $this->button('taxebay_disconnect', __('Disconnect', 'taxebay'), false);
        }

        return $html . '</div>';
    }

    private function urlRow(string $label, string $url): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td><code>' . esc_html($url) . '</code></td></tr>';
    }

    private function button(string $action, string $label, bool $primary): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:.5em">'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . wp_nonce_field($action, '_wpnonce', true, false)
            . get_submit_button($label, $primary ? 'primary' : 'secondary', 'submit', false)
            . '</form>';
    }
}
