<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Notification\AccountDeletion;
use TaxEbay\Core\Notification\NotificationSignature;
use TaxEbay\Core\Notification\PublicKeyClient;
use TaxEbay\Core\OAuth\TokenClient;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The public REST route eBay calls for Marketplace Account Deletion. It must be reachable without login — eBay is
 * the caller — so it reads nothing of the model and writes only a short log he sees in the eBay tab. A notification
 * counts only when eBay's signature holds; anything else is answered 412 and leaves no line.
 */
final class AccountDeletionEndpoint
{
    public const NAMESPACE = 'taxebay/v1';
    public const ROUTE = '/account-deletion';
    public const TOKEN = 'taxmod_ebay_deletion_token';
    public const LOG = 'taxmod_ebay_deletion_log';
    public const PROBLEM = 'taxmod_ebay_deletion_problem';
    private const LOG_SIZE = 50;
    private const KEY_CACHE = 'taxmod_ebay_notification_key_';

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            ['methods' => 'GET', 'callback' => self::challenge(...), 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => self::notification(...), 'permission_callback' => '__return_true'],
        ]);
    }

    public static function url(): string
    {
        return rest_url(self::NAMESPACE . self::ROUTE);
    }

    /** Made once, stored sealed (D-951); he copies it into the eBay form. */
    public static function token(): string
    {
        $stored = (string) get_option(self::TOKEN, '');

        if ($stored !== '') {
            $token = Settings::vault()->open($stored);

            if (AccountDeletion::isValidToken($token)) {
                return $token;
            }
        }

        $token = AccountDeletion::newToken();
        update_option(self::TOKEN, Settings::vault()->seal($token), false);

        return $token;
    }

    /** @return list<array{at: string, username: string, user_id: string}> newest first */
    public static function log(): array
    {
        $log = get_option(self::LOG, []);

        return is_array($log) ? array_values($log) : [];
    }

    private static function challenge(WP_REST_Request $request): WP_REST_Response
    {
        $code = sanitize_text_field((string) $request->get_param('challenge_code'));

        if ($code === '') {
            return new WP_REST_Response(['error' => 'challenge_code missing'], 400);
        }

        return new WP_REST_Response(['challengeResponse' => AccountDeletion::challengeResponse($code, self::token(), self::url())], 200);
    }

    private static function notification(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_body();
        $signature = NotificationSignature::fromHeader((string) $request->get_header('x_ebay_signature'));

        if ($signature === null) {
            return new WP_REST_Response(null, 412);
        }

        try {
            $signed = $signature->signs($body, self::publicKey($signature->keyId));
        } catch (EbayApiException $failure) {
            // eBay retries a notification that was not taken; a forged one fails the same way and costs one budgeted call.
            update_option(self::PROBLEM, current_time('mysql') . ' — ' . $failure->getMessage(), false);

            return new WP_REST_Response(null, 503);
        }

        if ($signed === null) {
            update_option(self::PROBLEM, current_time('mysql') . ' — ' . __('This server cannot check ECDSA/SHA-1 signatures (OpenSSL).', 'taxebay'), false);

            return new WP_REST_Response(null, 503);
        }

        if (! $signed) {
            return new WP_REST_Response(null, 412);
        }

        delete_option(self::PROBLEM);
        $deleted = AccountDeletion::deletedAccount((array) json_decode($body, true));

        if ($deleted !== null) {
            $entry = [
                'at'       => current_time('mysql'),
                'username' => mb_substr(sanitize_text_field($deleted['username']), 0, 64),
                'user_id'  => mb_substr(sanitize_text_field($deleted['user_id']), 0, 64),
            ];
            update_option(self::LOG, array_slice([$entry, ...self::log()], 0, self::LOG_SIZE), false);
        }

        return new WP_REST_Response(null, 204);
    }

    /** eBay's keys change rarely; one read per key id and day. */
    private static function publicKey(string $keyId): string
    {
        $cached = get_transient(self::KEY_CACHE . $keyId);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $keys = Settings::appKeys() ?? throw new EbayApiException(__('The app keys are missing.', 'taxebay'));
        $http = WpHttpTransport::budgeted();
        $token = (new TokenClient($keys, $http))->applicationToken(time())->accessToken;
        $key = (new PublicKeyClient($keys->environment, $http))->key($token, $keyId);
        set_transient(self::KEY_CACHE . $keyId, $key, DAY_IN_SECONDS);

        return $key;
    }
}
