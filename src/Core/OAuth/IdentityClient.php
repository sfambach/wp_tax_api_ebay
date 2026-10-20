<?php declare(strict_types=1);

namespace TaxEbay\Core\OAuth;

use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpRequest;
use TaxEbay\Core\Api\HttpTransport;

/**
 * Asks eBay whose account the token belongs to — the connection test, and nothing else (D-943, step 1).
 */
final class IdentityClient
{
    public function __construct(
        private readonly Environment $environment,
        private readonly HttpTransport $http,
    ) {
    }

    public function username(string $accessToken): string
    {
        $response = $this->http->send(new HttpRequest('GET', $this->environment->identityEndpoint(), [
            'Authorization' => 'Bearer ' . $accessToken,
            'Accept'        => 'application/json',
        ]));

        if ($response->status !== 200) {
            throw EbayApiException::fromResponse('Reading the account', $response);
        }

        $username = (string) ($response->json()['username'] ?? '');

        if ($username === '') {
            throw new EbayApiException('Reading the account: eBay returned no user name');
        }

        return $username;
    }
}
