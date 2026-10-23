<?php declare(strict_types=1);

namespace TaxEbay\Core\OAuth;

/**
 * What the module asks his consent for: read orders and fees, read who he is, and report a shipment (D-976). Nothing
 * that lists or sells yet.
 */
final class Scopes
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'https://api.ebay.com/oauth/api_scope',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
            'https://api.ebay.com/oauth/api_scope/sell.finances',
            'https://api.ebay.com/oauth/api_scope/commerce.identity.readonly',
        ];
    }

    public static function asString(): string
    {
        return implode(' ', self::all());
    }
}
