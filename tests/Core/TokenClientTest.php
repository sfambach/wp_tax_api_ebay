<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\EbayApiException;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpResponse;
use TaxEbay\Core\OAuth\AppKeys;
use TaxEbay\Core\OAuth\IdentityClient;
use TaxEbay\Core\OAuth\TokenClient;

final class TokenClientTest extends TestCase
{
    private function keys(Environment $environment = Environment::Production): AppKeys
    {
        return new AppKeys('client-id', 'secret', 'Stefan-RuName', $environment);
    }

    public function testConsentUrlCarriesRuNameScopesAndState(): void
    {
        $url = (new TokenClient($this->keys(), new ScriptedTransport([])))->consentUrl('abc');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith('https://auth.ebay.com/oauth2/authorize?', $url);
        self::assertSame('client-id', $query['client_id']);
        self::assertSame('Stefan-RuName', $query['redirect_uri']);
        self::assertSame('code', $query['response_type']);
        self::assertSame('abc', $query['state']);
        self::assertStringContainsString('sell.fulfillment ', (string) $query['scope']);
    }

    public function testSandboxUsesSandboxHosts(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, '{"access_token":"a","expires_in":7200}')]);
        $client = new TokenClient($this->keys(Environment::Sandbox), $http);

        self::assertStringStartsWith('https://auth.sandbox.ebay.com/', $client->consentUrl('s'));
        $client->refresh('r', 0);
        self::assertSame('https://api.sandbox.ebay.com/identity/v1/oauth2/token', $http->sent[0]->url);
    }

    public function testExchangeCodeReturnsBothTokens(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, json_encode([
            'access_token'             => 'access',
            'expires_in'               => 7200,
            'refresh_token'            => 'refresh',
            'refresh_token_expires_in' => 47304000,
        ], JSON_THROW_ON_ERROR))]);

        $tokens = (new TokenClient($this->keys(), $http))->exchangeCode('the code', 1000);

        self::assertSame('access', $tokens->accessToken);
        self::assertSame(8200, $tokens->accessExpiresAt);
        self::assertSame('refresh', $tokens->refreshToken);
        self::assertSame(47305000, $tokens->refreshExpiresAt);
        self::assertSame('Basic ' . base64_encode('client-id:secret'), $http->sent[0]->headers['Authorization']);
        parse_str($http->sent[0]->body, $form);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'the code', 'redirect_uri' => 'Stefan-RuName'], $form);
    }

    public function testExchangeWithoutRefreshTokenIsRefused(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, '{"access_token":"a","expires_in":7200}')]);

        $this->expectException(EbayApiException::class);
        (new TokenClient($this->keys(), $http))->exchangeCode('c', 0);
    }

    public function testRefusalCarriesEbaysReason(): void
    {
        $http = new ScriptedTransport([new HttpResponse(400, '{"error":"invalid_grant","error_description":"the provided authorization refresh token is invalid"}')]);

        $this->expectExceptionMessage('HTTP 400 the provided authorization refresh token is invalid');
        (new TokenClient($this->keys(), $http))->refresh('old', 0);
    }

    public function testIdentityReadsUsername(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, '{"userId":"x","username":"stefam"}')]);

        self::assertSame('stefam', (new IdentityClient(Environment::Production, $http))->username('token'));
        self::assertSame('Bearer token', $http->sent[0]->headers['Authorization']);
        self::assertSame('https://apiz.ebay.com/commerce/identity/v1/user/', $http->sent[0]->url);
    }
}
