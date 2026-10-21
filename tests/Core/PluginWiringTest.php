<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\WordPress\Plugin;

/**
 * Every handler `boot()` hooks must exist. Loads no WordPress: it reads the source and reflects the class.
 * Added after 0.0.3–0.0.6 shipped without the connect, return and test handlers.
 */
final class PluginWiringTest extends TestCase
{
    public function testEveryHookedMethodExists(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php');
        preg_match_all('/\$plugin->(\w+)\(\.\.\.\)/', $source, $hooked);

        self::assertNotEmpty($hooked[1]);

        foreach ($hooked[1] as $method) {
            self::assertTrue(method_exists(Plugin::class, $method), $method . ' is hooked but missing');
        }
    }

    public function testEveryActionLinkOfTheSettingsHasAHandler(): void
    {
        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Plugin.php');
        $screen = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/SettingsScreen.php');
        preg_match_all("/action\\('(taxebay_\\w+)'/", $screen, $linked);

        foreach (array_unique($linked[1]) as $action) {
            self::assertStringContainsString("'admin_post_" . $action . "'", $plugin, $action . ' has no handler');
        }
    }
}
