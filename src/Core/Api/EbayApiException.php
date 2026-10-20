<?php declare(strict_types=1);

namespace TaxEbay\Core\Api;

use RuntimeException;

/**
 * eBay refused or could not be reached; the message is fit to show him.
 */
final class EbayApiException extends RuntimeException
{
    public static function fromResponse(string $what, HttpResponse $response): self
    {
        $json = $response->json();
        $detail = (string) ($json['error_description'] ?? $json['errors'][0]['message'] ?? $json['error'] ?? '');

        return new self(trim(sprintf('%s: HTTP %d %s', $what, $response->status, $detail)));
    }
}
