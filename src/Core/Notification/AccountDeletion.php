<?php declare(strict_types=1);

namespace TaxEbay\Core\Notification;

/**
 * eBay's Marketplace Account Deletion notification, which a production keyset must answer: a GET with a challenge
 * code proves the endpoint, a POST reports an eBay account that was deleted.
 *
 * ```mermaid
 * flowchart LR
 *   G["GET ?challenge_code=c"] --> H["sha256(c + token + endpoint)"] --> J["{challengeResponse}"]
 *   P["POST notification"] --> U["username, userId"] --> L["log for him: remove from the model"]
 * ```
 *
 * ⚠️ Follows eBay's documentation; not measured. The POST's signature is not checked — a forged one only adds a line
 * to the log, it never changes data.
 */
final class AccountDeletion
{
    /** eBay's rule for the verification token: 32 to 80 characters, letters, digits, underscore, hyphen. */
    public static function isValidToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{32,80}$/', $token) === 1;
    }

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    }

    /** @param string $endpoint exactly the URL entered at eBay */
    public static function challengeResponse(string $challengeCode, string $token, string $endpoint): string
    {
        return hash('sha256', $challengeCode . $token . $endpoint);
    }

    /**
     * @param array<string, mixed> $body the POSTed JSON
     * @return array{username: string, user_id: string}|null null when it is not an account deletion
     */
    public static function deletedAccount(array $body): ?array
    {
        if (($body['metadata']['topic'] ?? '') !== 'MARKETPLACE_ACCOUNT_DELETION') {
            return null;
        }

        $data = (array) ($body['notification']['data'] ?? []);

        return ['username' => (string) ($data['username'] ?? ''), 'user_id' => (string) ($data['userId'] ?? '')];
    }
}
