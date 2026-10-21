<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\SecretBox;

/** What 0.0.1–0.0.4 sealed with their own vault must open with the modeller's (D-952). */
final class VaultCompatibilityTest extends TestCase
{
    public function testTokenSealedByTheOldVaultOpens(): void
    {
        $salt = 'auth salt from wp-config';
        $oldKey = sodium_crypto_generichash('taxebay-token|' . $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealedByOldVault = base64_encode($nonce . sodium_crypto_secretbox('v^1.1#refresh', $nonce, $oldKey));

        self::assertSame('v^1.1#refresh', (new SecretBox($salt, 'taxebay-token'))->open($sealedByOldVault));
    }
}
