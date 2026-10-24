<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * The «print your label» link in DHL's mail for a label bought at eBay. It opens the label PDF without a login, so
 * the plugin fetches it for him — and fetches nothing else: the address is checked before any request (no open proxy).
 */
final class DhlLabelLink
{
    private const HOST = 'www.dhl.de';
    private const PATH = '/int-versenden/gw/rest/init';

    public static function accepts(string $url): bool
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== self::HOST || ($parts['path'] ?? '') !== self::PATH) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        parse_str($parts['query'] ?? '', $query);

        return is_string($query['token'] ?? null) && $query['token'] !== '';
    }
}
