<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaxEbay\Core\Secret\TokenVault;

final class TokenVaultTest extends TestCase
{
    public function testSealedTokenOpensAndIsNotReadable(): void
    {
        $vault = new TokenVault('salt from wp-config');
        $sealed = $vault->seal('v^1.1#refresh-token');

        self::assertStringNotContainsString('refresh-token', (string) base64_decode($sealed, true));
        self::assertSame('v^1.1#refresh-token', $vault->open($sealed));
    }

    public function testAnotherSecretCannotOpen(): void
    {
        $sealed = (new TokenVault('one'))->seal('token');

        $this->expectException(RuntimeException::class);
        (new TokenVault('two'))->open($sealed);
    }

    public function testDamagedTextIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        (new TokenVault('one'))->open('not sealed');
    }
}
