<?php declare(strict_types=1);

namespace TaxEbay\Core\OAuth;

use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\HttpRequest;
use TaxEbay\Core\Api\HttpTransport;

/**
 * The OAuth authorization-code flow against eBay: the consent link, code for tokens, refresh for a new access token.
 *
 * ```mermaid
 * flowchart LR
 *   S["Settings: Connect"] --> C["eBay consent page"]
 *   C -->|"code + state"| R["admin-post return"]
 *   R --> X["exchangeCode"] --> V["refresh token sealed in an option"]
 *   V --> F["refresh: access token, two hours"]
 * ```
 */
final class TokenClient
{
    public function __construct(
        private readonly AppKeys $keys,
        private readonly HttpTransport $http,
    ) {
    }

    public function consentUrl(string $state): string
    {
        return $this->keys->environment->consentEndpoint() . '?' . http_build_query([
            'client_id'     => $this->keys->clientId,
            'redirect_uri'  => $this->keys->ruName,
            'response_type' => 'code',
            'scope'         => Scopes::asString(),
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, int $now): Tokens
    {
        $tokens = $this->request([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->keys->ruName,
        ], 'Exchanging the consent code', $now);

        if ($tokens->refreshToken === null) {
            throw new EbayApiException('Exchanging the consent code: eBay returned no refresh token');
        }

        return $tokens;
    }

    public function refresh(string $refreshToken, int $now): Tokens
    {
        return $this->request([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope'         => Scopes::asString(),
        ], 'Refreshing the access token', $now);
    }

    /** @param array<string, string> $form */
    private function request(array $form, string $what, int $now): Tokens
    {
        $response = $this->http->send(new HttpRequest('POST', $this->keys->environment->tokenEndpoint(), [
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => $this->keys->basicAuthorization(),
        ], http_build_query($form, '', '&', PHP_QUERY_RFC3986)));

        if ($response->status !== 200) {
            throw EbayApiException::fromResponse($what, $response);
        }

        $tokens = Tokens::fromTokenResponse($response->json(), $now);

        if ($tokens->accessToken === '') {
            throw new EbayApiException($what . ': eBay returned no access token');
        }

        return $tokens;
    }
}
