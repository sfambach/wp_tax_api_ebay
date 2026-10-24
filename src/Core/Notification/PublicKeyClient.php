<?php declare(strict_types=1);

namespace TaxEbay\Core\Notification;

use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpRequest;
use TaxEbay\Core\Api\HttpTransport;

/**
 * Reads the public key eBay signs notifications with, by the key id the signature names.
 */
final class PublicKeyClient
{
    public function __construct(
        private readonly Environment $environment,
        private readonly HttpTransport $http,
    ) {
    }

    public function key(string $applicationToken, string $keyId): string
    {
        $response = $this->http->send(new HttpRequest(
            'GET',
            $this->environment->apiHost() . '/commerce/notification/v1/public_key/' . rawurlencode($keyId),
            ['Authorization' => 'Bearer ' . $applicationToken, 'Accept' => 'application/json']
        ));

        if ($response->status !== 200) {
            throw EbayApiException::fromResponse('Reading the notification key', $response);
        }

        $key = (string) ($response->json()['key'] ?? '');

        if ($key === '') {
            throw new EbayApiException('Reading the notification key: eBay returned no key');
        }

        return $key;
    }
}
