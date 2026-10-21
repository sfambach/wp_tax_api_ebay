<?php declare(strict_types=1);

namespace TaxEbay\WordPress;

use TaxEbay\Core\Notification\AccountDeletion;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The public REST route eBay calls for Marketplace Account Deletion. It must be reachable without login — eBay is
 * the caller — so it reads nothing of the model and writes only a short log he sees in the eBay tab.
 */
final class AccountDeletionEndpoint
{
    public const NAMESPACE = 'taxebay/v1';
    public const ROUTE = '/account-deletion';
    public const TOKEN = 'taxmod_ebay_deletion_token';
    public const LOG = 'taxmod_ebay_deletion_log';
    private const LOG_SIZE = 50;

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
        $deleted = AccountDeletion::deletedAccount((array) $request->get_json_params());

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
}
