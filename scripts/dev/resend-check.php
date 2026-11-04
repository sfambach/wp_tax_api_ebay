<?php declare(strict_types=1);

/**
 * D-1168 against a local WordPress: the shipping page offers «Send again» on a parcel that left, shows the resend with
 * the first number struck out, asks for the new number while there is none, and offers taking it back.
 *
 *     php scripts/dev/resend-check.php [path/to/wordpress]
 *
 * Renders only; eBay is never called. Needs the Taxonomy Modeller active (WP_ROOT overrides the path).
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: '/home/user/wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require __DIR__ . '/../../vendor/autoload.php';

use TaxEbay\Core\Shipping\OpenShipment;
use TaxEbay\Core\Shipping\Resends;
use TaxEbay\Core\Shipping\Stages;
use TaxEbay\WordPress\ShippingScreen;

wp_set_current_user(1);

$red = 0;
$check = static function (bool $ok, string $what) use (&$red): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    $red += $ok ? 0 : 1;
};

$sale = new OpenShipment('24-15238-03470', 'guido_online', '2026-10-20', true, [['line_item_id' => '1', 'title' => 'Arduino FDC', 'quantity' => 1, 'sku' => '']], ['Guido', 'Weg 1', '12345 Stadt', 'DE'], 'DE_DeutschePostWarensendung', '12.00', 'EUR', true, 'DE', 'A005EFF1A1000000040B');
$now = time();
$stages = ['24-15238-03470' => ['stage' => Stages::SHIPPED, 'at' => $now]];

$html = (new ShippingScreen())->page([$sale], [], 'stefam76', $stages, Stages::SHIPPED, $now, resends: [], lossLots: [42 => 'Arduino FDC · 2026-10-01 · Rest 3']);
$check(str_contains($html, 'name="action" value="taxebay_resend"') && str_contains($html, 'Send this parcel again') && str_contains($html, 'taxmod-dialog-open'), 'a shipped parcel offers «Send again» in the shared dialog');
$check(str_contains($html, '<option value="42">Arduino FDC · 2026-10-01 · Rest 3</option>') && str_contains($html, 'name="quantity" value="1"'), 'the dialog offers the stock lots and the quantity of the parcel');
$check(str_contains($html, 'value="' . Resends::LOST . '"') && ! str_contains($html, 'taxebay_resend_undo'), 'reasons offered, nothing to take back yet');

$open = (new ShippingScreen())->page([$sale], [], 'stefam76', $stages, Stages::OPEN, $now, resends: []);
$check(! str_contains($open, 'name="action" value="taxebay_resend"'), 'a parcel not sent yet offers no resend');

$resends = Resends::add([], ['24-15238-03470'], ['at' => $now, 'reason' => Resends::LOST, 'note' => '', 'tracking' => '', 'previous_tracking' => 'A005EFF1A1000000040B', 'previous_stage' => Stages::SHIPPED, 'deal' => 9, 'who' => 'admin']);
$after = (new ShippingScreen())->page([$sale], [], 'stefam76', ['24-15238-03470' => ['stage' => Stages::OPEN, 'at' => $now]], Stages::OPEN, $now, resends: $resends);
$check(str_contains($after, 'sent again (1×): lost in the post') && str_contains($after, '<s>A005EFF1A1000000040B</s>'), 'the resent parcel says so and strikes the first number');
$check(str_contains($after, 'taxebay_resend_tracking') && str_contains($after, 'taxebay_resend_undo') && str_contains($after, 'the loss booking is cancelled'), 'it asks for the new number and offers taking back, naming the Storno');

$tracked = (new ShippingScreen())->page([$sale->withTracking('A005EFF1A10000000472')], [], 'stefam76', ['24-15238-03470' => ['stage' => Stages::OPEN, 'at' => $now]], Stages::OPEN, $now, resends: Resends::withTracking($resends, ['24-15238-03470'], 'A005EFF1A10000000472'));
$check(str_contains($tracked, 'A005EFF1A10000000472') && ! str_contains($tracked, 'taxebay_resend_tracking'), 'with the new number entered it is shown and no longer asked for');

$check(TaxEbay\WordPress\SaleRecord::lookup() === null || TaxEbay\WordPress\SaleRecord::lookup() instanceof TaxEbay\WordPress\SaleRecord, 'the sale lookup loads against this modeller');

echo $red === 0 ? "resend-check: all green\n" : "resend-check: {$red} FAIL\n";
exit($red === 0 ? 0 : 1);
