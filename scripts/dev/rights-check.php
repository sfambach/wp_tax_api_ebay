<?php declare(strict_types=1);
/**
 * The connection checks its own right (D-1585 step 3, D-1586): setting it up needs configure, and no old right is left.
 *
 *     php scripts/dev/rights-check.php [path/to/wordpress]
 *
 * Needs the Taxonomy Modeller ab 0.2.237 active (WP_ROOT overrides the path).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Rights;
use TaxEbay\WordPress\Plugin;

$bad = 0;
$pruefe = static function (bool $ok, string $what) use (&$bad): void {
    echo ($ok ? '  OK   ' : '  FAIL ') . $what . "\n";
    $bad += $ok ? 0 : 1;
};

$old = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $old += preg_match_all('/\'manage_options\'|\bModeller::CAPABILITY\b|Taxmod\\\\WordPress\\\\Plugin::CAPABILITY\b/', (string) file_get_contents($file->getPathname()));
    }
}
$pruefe($old === 0, "no old right left in src ($old)");
$pruefe((new ReflectionClassConstant(Plugin::class, 'CAPABILITY'))->getValue() === Rights::CONFIGURE, 'setting the connection up asks configure');
$acts = (new ReflectionClassConstant(Plugin::class, 'SHIPPING_ACTS'))->getValue();
$pruefe(in_array(\TaxEbay\WordPress\ShippingScreen::SHIP, $acts, true) && in_array(\TaxEbay\WordPress\ShippingScreen::LABEL_PDF, $acts, true), 'the acts of the shipping page ask ship');

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
