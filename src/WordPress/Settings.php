<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\OAuth\AppKeys;
use TaxEbay\Core\Secret\TokenVault;

/**
 * Where the module's configuration comes from: app keys from wp-config.php, the connection from one option (D-943).
 */
final class Settings
{
    public const CONNECTION = 'taxebay_connection';

    /** null while wp-config.php lacks the keys */
    public static function appKeys(): ?AppKeys
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
