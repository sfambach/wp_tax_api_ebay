<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod API eBay
 * Description:       Connects the owner's eBay account and offers eBay sales and purchases as proposals for the Taxonomy Modeller; only picked items are written.
 * Version:           0.0.8
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Stefan Fambach (SteFam)
 * Author URI:        https://www.fambach.net
 * License:           PolyForm Noncommercial 1.0.0
 * License URI:       https://polyformproject.org/licenses/noncommercial/1.0.0/
 * Text Domain:       taxebay
 * GitHub Plugin URI: sfambach/wp_tax_api_ebay
 * Primary Branch:    main
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md
 */

if (! defined('ABSPATH')) {
    exit;
}

$taxebayAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxebayAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Taxmod API eBay:</strong> '
            . esc_html__('run "composer install" in the plugin folder — the autoloader is missing.', 'taxebay')
            . '</p></div>';
    });

    return;
}

require_once $taxebayAutoloader;

// Vault, proposal format and the inbox's source contract live in the modeller since 0.1.35 (D-952, D-953).
add_action('plugins_loaded', static function (): void {
    if (! interface_exists(Taxmod\Core\Import\FetchingSource::class) || ! class_exists(Taxmod\WordPress\Import\Vault::class) || Taxmod\WordPress\Import\Vault::API < 1) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p><strong>Taxmod API eBay:</strong> '
                . esc_html__('needs the Taxonomy Modeller 0.1.35 or later — the module stays off until then.', 'taxebay')
                . '</p></div>';
        });

        return;
    }

    TaxEbay\WordPress\Plugin::boot();
});
