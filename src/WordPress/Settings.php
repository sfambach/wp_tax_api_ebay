<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use Taxmod\Core\Exception\SecretDoesNotOpen;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\OAuth\AppKeys;
use TaxEbay\Core\Shipping\Stages;
use Taxmod\Core\Import\SecretBox;
use Taxmod\WordPress\Import\Vault;

/**
 * Where the module's configuration comes from: app keys from wp-config.php or, sealed, from an option; the connection
 * from another option (D-943).
 */
final class Settings
{
    public const CONNECTION = 'taxmod_ebay_connection';

    public const KEYS = 'taxmod_ebay_keys';
    public const SCHEDULE = 'taxmod_ebay_schedule';
    public const INSERT_FORMAT = 'taxmod_ebay_insert_format';
    public const STAGES = 'taxmod_ebay_stages';
    /** Open buyer inquiries and cases as eBay named them at the last refresh, per order. */
    public const DISPUTES = 'taxmod_ebay_disputes';
    private const PACKED_BEFORE_STAGES = 'taxmod_ebay_packed';
    public const INSERT_A6 = 'a6';
    public const INSERT_LABEL = 'label62';

    /** Seconds between two automatic fetches, by the label he picks; 0 means the inbox button only (D-943 E7). */
    public const INTERVALS = ['' => 0, 'hourly' => 3600, 'fourtimesdaily' => 21600, 'twicedaily' => 43200, 'daily' => 86400];

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
        } catch (SecretDoesNotOpen) {
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

    public static function schedule(): string
    {
        $stored = (string) get_option(self::SCHEDULE, 'twicedaily');

        return array_key_exists($stored, self::INTERVALS) ? $stored : 'twicedaily';
    }

    public static function fetchInterval(): int
    {
        return self::INTERVALS[self::schedule()];
    }

    public static function saveSchedule(string $schedule): void
    {
        update_option(self::SCHEDULE, array_key_exists($schedule, self::INTERVALS) ? $schedule : 'twicedaily', false);
    }

    /** The modeller's vault (D-951, D-952); the context is the one this module sealed with before, so stored secrets still open. */
    public static function vault(): SecretBox
    {
        return Vault::for('taxebay-token');
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
            'scopes'          => \TaxEbay\Core\OAuth\Scopes::asString(),
        ], false);
    }

    /** A consent given before the module asked for more rights must be renewed with «Connect again». */
    public static function needsReconnect(): bool
    {
        $stored = get_option(self::CONNECTION, null);

        return is_array($stored) && ($stored['scopes'] ?? '') !== \TaxEbay\Core\OAuth\Scopes::asString();
    }

    /** Size of the parcel insert: A6 sheet or 62 mm tape of his Brother QL-810W. */
    public static function insertFormat(): string
    {
        return get_option(self::INSERT_FORMAT, self::INSERT_A6) === self::INSERT_LABEL ? self::INSERT_LABEL : self::INSERT_A6;
    }

    public static function saveInsertFormat(string $format): void
    {
        update_option(self::INSERT_FORMAT, $format === self::INSERT_LABEL ? self::INSERT_LABEL : self::INSERT_A6, false);
    }

    /** A stage note is kept this long; the shipping page reads eBay only that far back (D-985). */
    public const STAGE_DAYS = 30;

    /** @return array<string, array{stage: string, at: int}> order id → his note */
    public static function stages(): array
    {
        $stages = [];

        foreach ((array) get_option(self::STAGES, []) as $orderId => $note) {
            if (is_array($note) && isset($note['stage'], $note['at'])) {
                $stages[(string) $orderId] = ['stage' => (string) $note['stage'], 'at' => (int) $note['at']];
            }
        }

        // 0.0.40 kept only «packed», as order id → time.
        foreach ((array) get_option(self::PACKED_BEFORE_STAGES, []) as $orderId => $at) {
            $stages[(string) $orderId] ??= ['stage' => Stages::PACKED, 'at' => (int) $at];
        }

        return $stages;
    }

    /** @param list<string> $orderIds */
    public static function saveStage(array $orderIds, string $stage, int $now): void
    {
        $kept = array_filter(self::stages(), static fn (array $note): bool => $note['at'] > $now - self::STAGE_DAYS * 86400);

        foreach ($orderIds as $orderId) {
            if ($stage === Stages::OPEN) {
                unset($kept[$orderId]);
            } else {
                $kept[$orderId] = ['stage' => $stage, 'at' => $now];
            }
        }

        update_option(self::STAGES, $kept, false);
        delete_option(self::PACKED_BEFORE_STAGES);
    }

    /** @return array<string, array{open: list<array{kind: string, id: string, status: string, respond_by: string}>, at: int}> order id → last word */
    public static function disputes(): array
    {
        $stored = get_option(self::DISPUTES, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Keeps eBay's answer from a refresh; notes older than the stage notes go.
     *
     * @param array<string, list<array{kind: string, id: string, status: string, respond_by: string}>> $byOrder
     */
    public static function saveDisputes(array $byOrder, int $now): void
    {
        $kept = array_filter(self::disputes(), static fn (array $note): bool => (int) ($note['at'] ?? 0) > $now - self::STAGE_DAYS * 86400);

        foreach ($byOrder as $orderId => $open) {
            $kept[$orderId] = ['open' => $open, 'at' => $now];
        }

        update_option(self::DISPUTES, $kept, false);
    }

    /** «Delete access data» (D-981): the app keys go too; constants in wp-config.php stay, they are his file. */
    public static function forgetKeys(): void
    {
        delete_option(self::KEYS);
    }

    public static function forget(): void
    {
        delete_option(self::CONNECTION);
    }
}
