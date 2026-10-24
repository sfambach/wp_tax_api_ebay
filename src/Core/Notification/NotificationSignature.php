<?php declare(strict_types=1);

namespace TaxEbay\Core\Notification;

/**
 * The `X-EBAY-SIGNATURE` header of an eBay notification: which public key signed it, and whether the body is what
 * that key signed. Without it anybody could post a «deleted account» and he would remove a real buyer from the model.
 *
 * ```mermaid
 * flowchart LR
 *   H["X-EBAY-SIGNATURE: base64 JSON"] --> K["kid, signature"]
 *   K --> P["public key of kid (Notification API, cached)"]
 *   P --> V["ECDSA / SHA-1 over the raw body"]
 * ```
 *
 * ⚠️ Follows eBay's documentation and SDKs; not yet measured against a real notification.
 */
final class NotificationSignature
{
    private function __construct(
        public readonly string $keyId,
        private readonly string $signature,
    ) {
    }

    /** null when the header is missing, not eBay's format, or names another algorithm */
    public static function fromHeader(string $header): ?self
    {
        $decoded = base64_decode(trim($header), true);
        $fields = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($fields)) {
            return null;
        }

        $keyId = (string) ($fields['kid'] ?? '');
        $signature = base64_decode((string) ($fields['signature'] ?? ''), true);

        if (strtolower((string) ($fields['alg'] ?? '')) !== 'ecdsa' || strtoupper((string) ($fields['digest'] ?? '')) !== 'SHA1'
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $keyId) !== 1 || $signature === false || $signature === '') {
            return null;
        }

        return new self($keyId, $signature);
    }

    /**
     * @param string $publicKey as the Notification API returns it, with or without line breaks
     * @return bool|null null when this PHP cannot check it at all (no OpenSSL, or SHA-1 refused by its policy)
     */
    public function signs(string $body, string $publicKey): ?bool
    {
        if (! function_exists('openssl_verify')) {
            return null;
        }

        $key = openssl_pkey_get_public(self::pem($publicKey));

        if ($key === false) {
            return false;
        }

        $result = openssl_verify($body, $this->signature, $key, OPENSSL_ALGO_SHA1);

        return $result === -1 || $result === false ? null : $result === 1;
    }

    /** eBay hands the key out in one line; OpenSSL wants the base64 in lines between the markers. */
    public static function pem(string $publicKey): string
    {
        $base64 = (string) preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicKey);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($base64, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }
}
