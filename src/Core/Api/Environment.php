<?php declare(strict_types=1);

namespace TaxEbay\Core\Api;

/**
 * eBay production or sandbox: the hosts differ, nothing else does.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/ebay/konzept.md
 */
enum Environment: string
{
    case Production = 'production';
    case Sandbox    = 'sandbox';

    public function consentEndpoint(): string
    {
        return match ($this) {
            self::Production => 'https://auth.ebay.com/oauth2/authorize',
            self::Sandbox    => 'https://auth.sandbox.ebay.com/oauth2/authorize',
        };
    }

    public function tokenEndpoint(): string
    {
        return $this->apiHost() . '/identity/v1/oauth2/token';
    }

    public function apiHost(): string
    {
        return match ($this) {
            self::Production => 'https://api.ebay.com',
            self::Sandbox    => 'https://api.sandbox.ebay.com',
        };
    }

    /** The Identity API lives on its own host. */
    public function identityEndpoint(): string
    {
        return match ($this) {
            self::Production => 'https://apiz.ebay.com/commerce/identity/v1/user/',
            self::Sandbox    => 'https://apiz.sandbox.ebay.com/commerce/identity/v1/user/',
        };
    }
}
