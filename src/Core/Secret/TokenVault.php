<?php declare(strict_types=1);

namespace TaxEbay\Core\Secret;

use RuntimeException;

/**
 * Seals the refresh token before it is stored, so a backup or a database dump does not carry it readable (D-943).
 * The key is derived from a secret that lives in wp-config.php, outside the database.
 */
final class TokenVault
{
    private readonly string $key;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new RuntimeException('No secret to seal the token with');
        }

        $this->key = sodium_crypto_generichash('taxebay-token|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /** @throws RuntimeException when the sealed text was changed or the secret is a different one */
    public function open(string $sealed): string
    {
        $raw = base64_decode($sealed, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('The stored token is damaged');
        }

        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key
        );

        if ($plain === false) {
            throw new RuntimeException('The stored token cannot be opened — was the secret in wp-config.php changed?');
        }

        return $plain;
    }
}
