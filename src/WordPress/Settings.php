<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use RuntimeException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\OAuth\AppKeys;
use TaxEbay\Core\Secret\TokenVault;

/**
 * Where the module's configuration comes from: app keys from wp-config.php or, sealed, from an option; the connection
 * from another option (D-943).
 */
final class Settings
{
    public const CONNECTION = 'taxmod_ebay_connection';

    public const KEYS = 'taxmod_ebay_keys';

    /** Constants in wp-config.php win; otherwise the keys entered on the settings page (his word 2026-10-20: «ja verschlüsselt einbauen»). */
    public static function appKeys(): ?AppKeys
    {
        return self::keysFromConstants() ?? self::keysFromOption();
    }

    public static function keysFromConstants(): ?AppKeys
    {
        foreach (['TAXEBAY_CLIENT_ID', 'TAXEBAY_CLIENT_SECRET', 'TAXEBAY_RUNAME'] as $name) {
            if (! defined($name) || (string) constant($name) === '') {
                return null;
            }
        }

        return new AppKeys(
            (string) constant('TAXEBAY_CLIENT_ID'),
            (string) constant('TAXEBAY_CLIENT_SECRET'),
            (string) constant('TAXEBAY_RUNAME'),
            defined('TAXEBAY_SANDBOX') && constant('TAXEBAY_SANDBOX') ? Environment::Sandbox : Environment::Production,
        );
    }

    /** The secret is stored sealed; a key that cannot be opened counts as missing. */
    private static function keysFromOption(): ?AppKeys
    {
        $stored = self::storedKeys();

        if ($stored['client_id'] === '' || $stored['client_secret'] === '' || $stored['runame'] === '') {
            return null;
        }

        try {
            $secret = self::vault()->open($stored['client_secret']);
        } catch (RuntimeException) {
            return null;
        }

        return new AppKeys(
            $stored['client_id'],
            $secret,
            $stored['runame'],
            $stored['sandbox'] ? Environment::Sandbox : Environment::Production,
        );
    }

    /** @return array{client_id: string, client_secret: string, runame: string, sandbox: bool} client_secret still sealed */
    public static function storedKeys(): array
    {
        $stored = get_option(self::KEYS, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'client_id'     => (string) ($stored['client_id'] ?? ''),
            'client_secret' => (string) ($stored['client_secret'] ?? ''),
            'runame'        => (string) ($stored['runame'] ?? ''),
            'sandbox'       => (bool) ($stored['sandbox'] ?? false),
        ];
    }

    /** An empty secret keeps the stored one, so the form never has to show it. */
    public static function saveKeys(string $clientId, string $plainSecret, string $ruName, bool $sandbox): void
    {
        $stored = self::storedKeys();

        update_option(self::KEYS, [
            'client_id'     => $clientId,
            'client_secret' => $plainSecret === '' ? $stored['client_secret'] : self::vault()->seal($plainSecret),
            'runame'        => $ruName,
            'sandbox'       => $sandbox,
        ], false);
    }

    /** The auth salt comes from wp-config.php, so the sealed token is useless without that file. */
    public static function vault(): TokenVault
    {
        return new TokenVault(wp_salt('auth'));
    }

    /** @return array{refresh: string, refresh_expires: int, username: string, connected_at: string}|null */
    public static function connection(): ?array
    {
        $stored = get_option(self::CONNECTION, null);

        if (! is_array($stored) || ! is_string($stored['refresh'] ?? null) || $stored['refresh'] === '') {
            return null;
        }

        return [
            'refresh'         => $stored['refresh'],
            'refresh_expires' => (int) ($stored['refresh_expires'] ?? 0),
            'username'        => (string) ($stored['username'] ?? ''),
            'connected_at'    => (string) ($stored['connected_at'] ?? ''),
        ];
    }

    public static function saveConnection(string $sealedRefresh, int $refreshExpires, string $username): void
    {
        update_option(self::CONNECTION, [
            'refresh'         => $sealedRefresh,
            'refresh_expires' => $refreshExpires,
            'username'        => $username,
            'connected_at'    => current_time('mysql'),
        ], false);
    }

    public static function forget(): void
    {
        delete_option(self::CONNECTION);
    }
}
