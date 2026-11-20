<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\WordPress\Plugin;

/** D-1507: the 62 mm print lives in the Etiketten module; eBay keeps no copy and attaches it only by its handle. */
final class LabelPrintMovedTest extends TestCase
{
    public function testNoCopyStaysHere(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileDoesNotExist($root . '/assets/label62.js');
        self::assertDirectoryDoesNotExist($root . '/assets/pdfjs');
        self::assertStringNotContainsString('pdfjsLib', (string) file_get_contents($root . '/assets/label-link.js'));
    }

    public function testAttachedByHandleAndHiddenWithoutIt(): void
    {
        $root = dirname(__DIR__, 2);
        $plugin = (string) file_get_contents($root . '/src/WordPress/Plugin.php');
        $screen = (string) file_get_contents($root . '/src/WordPress/ShippingScreen.php');

        self::assertSame('taxmod-label62', Plugin::LABEL62);
        self::assertStringContainsString("[self::LABEL62]", $plugin);
        self::assertStringContainsString("if (! wp_script_is(self::LABEL62, 'registered'))", $plugin);
        self::assertStringContainsString("wp_script_is(Plugin::LABEL62, 'registered')", $screen);
    }
}
