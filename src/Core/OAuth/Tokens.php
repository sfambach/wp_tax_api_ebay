<?php declare(strict_types=1);

namespace TaxEbay\Core\OAuth;

/**
 * An access token and, after consent, the long-lived refresh token. Times are Unix seconds.
 */
final class Tokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $accessExpiresAt,
        public readonly ?string $refreshToken = null,
        public readonly ?int $refreshExpiresAt = null,
    ) {
    }

    /** @param array<string, mixed> $json eBay's token answer */
    public static function fromTokenResponse(array $json, int $now): self
    {
        return new self(
            (string) ($json['access_token'] ?? ''),
            $now + (int) ($json['expires_in'] ?? 0),
            isset($json['refresh_token']) ? (string) $json['refresh_token'] : null,
            isset($json['refresh_token_expires_in']) ? $now + (int) $json['refresh_token_expires_in'] : null,
        );
    }
}
