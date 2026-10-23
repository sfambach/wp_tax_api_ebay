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
        $html = $this->credentials($notices);
        $html .= $this->accountDeletion() . $this->purchases(true) . $this->insertFormat();

        if (self::modellerDrawsGuide()) {
            return $html;
        }

        return '<div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start"><div style="flex:1 1 40em;min-width:0">'
            . $html . '</div><aside class="postbox" style="flex:0 1 24em;padding:0 16px 8px"><h2>'
            . esc_html__('How to connect', 'taxebay') . '</h2>' . $this->guide() . '</aside></div>';
    }

    /**
     * The access part (D-981): keys, the URLs for the RuName, the eBay consent. With the shared layout the modeller draws
     * «Save», «Test connection» and «Delete access data» beneath it; connect and disconnect stay here, only eBay has a
     * consent.
     *
     * @param list<array{ok: bool, text: string}> $notices
     */
    public function credentials(array $notices = []): string
    {
        $html = '';

        foreach ($notices as $notice) {
            $html .= '<div class="notice ' . ($notice['ok'] ? 'notice-success' : 'notice-error') . ' inline"><p>'
                . esc_html($notice['text']) . '</p></div>';
        }

        $keys = Settings::appKeys();
        $html .= '<h3>' . esc_html__('App keys', 'taxebay') . '</h3>';

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

        $html .= '<h3>' . esc_html__('URLs for the RuName at eBay', 'taxebay') . '</h3>'
            . '<table class="form-table" role="presentation"><tbody>'
            . $this->urlRow(__('Auth accepted URL', 'taxebay'), admin_url('admin-post.php?action=taxebay_return'))
            . $this->urlRow(__('Auth declined URL', 'taxebay'), Plugin::settingsUrl())
            . '</tbody></table>';

        $connection = Settings::connection();
        $html .= '<h3>' . esc_html__('eBay account', 'taxebay') . '</h3>';

        if ($connection === null) {
            return $html . '<p>' . esc_html__('Not connected.', 'taxebay')
                . ($keys === null ? ' ' . esc_html__('Save the keys first.', 'taxebay') : '') . '</p>'
                . ($keys === null ? '' : '<p>' . $this->action('taxebay_connect', __('Connect to eBay', 'taxebay'), true) . '</p>');
        }

        return $html . '<p>' . esc_html(sprintf(
            /* translators: 1: eBay user name, 2: date, 3: date the consent runs out */
            __('Connected as %1$s since %2$s. Consent valid until %3$s.', 'taxebay'),
            $connection['username'],
            $connection['connected_at'],
            $connection['refresh_expires'] > 0 ? wp_date('Y-m-d', $connection['refresh_expires']) : '?'
        )) . '</p>'
            . (Settings::needsReconnect() ? '<p class="notice notice-warning inline">' . esc_html__('The module asks for more rights since your consent (shipping). Press «Connect again».', 'taxebay') . '</p>' : '')
            . '<p>' . (self::sharedLayout() ? '' : $this->action('taxebay_check', __('Test connection', 'taxebay'), true) . ' ')
            . $this->action('taxebay_connect', __('Connect again', 'taxebay'), false) . ' '
            . $this->action('taxebay_disconnect', __('Disconnect', 'taxebay'), false) . '</p>';
    }

    /** The rest of the tab under the shared layout (D-981): eBay's deletion notice and the purchases. */
    public function settings(): string
    {
        return $this->accountDeletion() . $this->purchases(false) . $this->insertFormat();
    }

    /** Which printer the parcel insert is laid out for (page «eBay shipping»). */
    private function insertFormat(): string
    {
        $options = '';

        foreach ([Settings::INSERT_A6 => __('A6 (sheet printer)', 'taxebay'), Settings::INSERT_LABEL => __('62 mm label tape (Brother QL)', 'taxebay')] as $value => $label) {
            $options .= '<option value="' . esc_attr($value) . '"' . selected(Settings::insertFormat(), $value, false) . '>' . esc_html($label) . '</option>';
        }

        return '<h3>' . esc_html__('Shipping', 'taxebay') . '</h3><p><label for="taxebay-insert-format">' . esc_html__('Parcel insert', 'taxebay')
            . '</label> <select id="taxebay-insert-format" name="taxebay_insert_format">' . $options . '</select> <a href="'
            . esc_url(admin_url('admin.php?page=' . ShippingScreen::PAGE)) . '">' . esc_html__('Open eBay shipping', 'taxebay') . '</a></p>';
    }

    /** From modeller 0.1.49 the tab has the shared layout: access, fetching, settings (D-981). */
    public static function sharedLayout(): bool
    {
        return class_exists(\Taxmod\WordPress\Plugin::class) && version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.1.49', '>=');
    }

    /** From modeller 0.1.41 the tab's `guide` is drawn by the modeller beside the form (D-963). */
    public static function modellerDrawsGuide(): bool
    {
        return class_exists(\Taxmod\WordPress\Plugin::class) && version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.1.41', '>=');
    }

    /** The whole way from an eBay account to purchases in the inbox, beside the fields it talks about. */
    public function guide(): string
    {
        $steps = [
            __('Open developer.ebay.com and create a developer account (eBay approves it within about a day).', 'taxebay'),
            __('Under «Application Access Keys», create a Production keyset. Copy App ID and Cert ID into the app keys; the Dev ID is not needed.', 'taxebay'),
            __('Under «Alerts & Notifications», enter your email and save, then enter the notification endpoint and verification token from this tab and press «Send Test Notification». eBay will not release the keys without it.', 'taxebay'),
            __('Under «User Tokens» → «Get a Token from eBay via Your Application» → «Add eBay Redirect URL», enter the two URLs shown here, choose OAuth and save. Copy the RuName from that row into the app keys and save here.', 'taxebay'),
            __('Press «Connect to eBay», sign in at eBay and agree. Back here, «Test connection» shows your eBay name.', 'taxebay'),
            __('In the Inbox, press «Fetch now» for eBay. eBay answers for the last 90 days; afterwards the module fetches on the chosen interval.', 'taxebay'),
        ];
        $html = '<ol>';

        foreach ($steps as $step) {
            $html .= '<li>' . esc_html($step) . '</li>';
        }

        return $html . '</ol><p class="description">' . esc_html__('The consent lasts 18 months; then press «Connect again». Keys and tokens are stored encrypted.', 'taxebay') . '</p>';
    }

    /** What he enters at eBay under «Alerts & Notifications» → Marketplace Account Deletion, and what eBay reported. */
    private function accountDeletion(): string
    {
        $html = '<h3>' . esc_html__('Marketplace account deletion (required by eBay)', 'taxebay') . '</h3>'
            . '<p>' . esc_html__('Enter both values at eBay, then press «Send Test Notification» there.', 'taxebay') . '</p>'
            . '<table class="form-table" role="presentation"><tbody>'
            . $this->urlRow(__('Notification endpoint', 'taxebay'), AccountDeletionEndpoint::url())
            . $this->urlRow(__('Verification token', 'taxebay'), AccountDeletionEndpoint::token())
            . '</tbody></table>';
        $log = AccountDeletionEndpoint::log();

        if ($log !== []) {
            $html .= '<p>' . esc_html__('eBay reported these deleted accounts; remove their user names from the model if they occur there:', 'taxebay') . '</p><ul>';

            foreach (array_slice($log, 0, 10) as $entry) {
                $html .= '<li>' . esc_html($entry['at'] . ' — ' . ($entry['username'] !== '' ? $entry['username'] : $entry['user_id'])) . '</li>';
            }

            $html .= '</ul>';
        }

        return $html;
    }

    /** Purchases go to the modeller's inbox; its schedule asks this module how often (D-943 E7, D-953). */
    private function purchases(bool $withSchedule): string
    {
        $html = '<h3>' . esc_html__('Purchases and sales', 'taxebay') . '</h3><p>' . esc_html__('Your eBay purchases and sales arrive in the Inbox of the Taxonomy Modeller; eBay answers for about the last 90 days.', 'taxebay')
            . ' <a href="' . esc_url(admin_url('admin.php?page=taxmod-inbox')) . '">' . esc_html__('Open the Inbox', 'taxebay') . '</a></p>';

        if ($withSchedule) {
            $html .= '<p><label for="taxebay-schedule">' . esc_html__('Fetch automatically', 'taxebay') . '</label> <select id="taxebay-schedule" name="taxebay_schedule">';

            foreach ([
                ''               => __('never (Inbox button only)', 'taxebay'),
                'hourly'         => __('every hour', 'taxebay'),
                'fourtimesdaily' => __('four times a day', 'taxebay'),
                'twicedaily'     => __('twice a day', 'taxebay'),
                'daily'          => __('once a day', 'taxebay'),
            ] as $value => $label) {
                $html .= '<option value="' . esc_attr($value) . '"' . selected(Settings::schedule(), $value, false) . '>' . esc_html($label) . '</option>';
            }

            $html .= '</select></p>';
        }

        if (Settings::connection() !== null) {
            $html .= '<p>' . $this->action('taxebay_export', __('Download purchases as JSON', 'taxebay'), false)
                . ' <span class="description">' . esc_html__('The last 90 days exactly as eBay delivers them, to compare with the model. Writes nothing.', 'taxebay') . '</span></p>';
        }

        $used = (new OptionCallLedger())->counts(OptionCallLedger::today());
        $parts = [];

        foreach (\TaxEbay\Core\Api\CallBudget::CAPS as $family => $cap) {
            $parts[] = sprintf('%s %d/%d', $family, $used[$family] ?? 0, $cap);
        }

        return $html . '<p class="description">' . esc_html(sprintf(
            /* translators: %s: calls per API today, e.g. "trading 3/500" */
            __('eBay calls today (UTC), against the module\'s own daily cap: %s', 'taxebay'),
            implode(' · ', $parts)
        )) . '</p>';
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
