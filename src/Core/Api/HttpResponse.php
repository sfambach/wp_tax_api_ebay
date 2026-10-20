<?php declare(strict_types=1);

namespace TaxEbay\Core\Api;

/**
 * Status and body of an answer; transport failures are exceptions, not responses.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    /** @return array<string, mixed> `mixed` because eBay's JSON differs by endpoint */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
