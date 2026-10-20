<?php declare(strict_types=1);

namespace TaxEbay\Core\Api;

/**
 * One outgoing HTTP request, described without sending it.
 */
final class HttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }
}
