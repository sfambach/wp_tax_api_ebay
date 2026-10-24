<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Api\CallBudget;
use TaxEbay\Core\Api\Environment;
use TaxEbay\Core\Api\HttpResponse;
use TaxEbay\Core\Notification\NotificationSignature;
use TaxEbay\Core\Notification\PublicKeyClient;

final class NotificationSignatureTest extends TestCase
{
    private const BODY = '{"metadata":{"topic":"MARKETPLACE_ACCOUNT_DELETION"},"notification":{"data":{"username":"retro_fan_42","userId":"u1"}}}';

    public function testAcceptsTheBodyTheKeySigned(): void
    {
        [$header, $publicKey] = self::signed(self::BODY);
        $signature = NotificationSignature::fromHeader($header);

        self::assertNotNull($signature);
        self::assertSame('kid-1', $signature->keyId);
        self::assertTrue($signature->signs(self::BODY, $publicKey));
    }

    public function testRefusesAnAlteredBodyAndAnotherKey(): void
    {
        [$header, $publicKey] = self::signed(self::BODY);
        [, $otherKey] = self::signed(self::BODY);
        $signature = NotificationSignature::fromHeader($header);

        self::assertFalse($signature?->signs(str_replace('retro_fan_42', 'someone_else', self::BODY), $publicKey));
        self::assertFalse($signature?->signs(self::BODY, $otherKey));
    }

    public function testReadsEbaysOneLineKey(): void
    {
        [$header, $publicKey] = self::signed(self::BODY);
        $oneLine = str_replace("\n", '', $publicKey);

        self::assertTrue(NotificationSignature::fromHeader($header)?->signs(self::BODY, $oneLine));
    }

    public function testRefusesHeadersThatAreNotEbays(): void
    {
        self::assertNull(NotificationSignature::fromHeader(''));
        self::assertNull(NotificationSignature::fromHeader('not base64 !'));
        self::assertNull(NotificationSignature::fromHeader(base64_encode('{"alg":"rsa","kid":"k","signature":"YQ==","digest":"SHA1"}')));
        self::assertNull(NotificationSignature::fromHeader(base64_encode('{"alg":"ecdsa","kid":"../x","signature":"YQ==","digest":"SHA1"}')));
        self::assertNull(NotificationSignature::fromHeader(base64_encode('{"alg":"ecdsa","kid":"k","signature":"","digest":"SHA1"}')));
    }

    public function testPublicKeyIsReadByKeyIdWithTheAppToken(): void
    {
        $http = new ScriptedTransport([new HttpResponse(200, '{"key":"-----BEGIN PUBLIC KEY-----abc-----END PUBLIC KEY-----","algorithm":"ECDSA","digest":"SHA1"}')]);
        $key = (new PublicKeyClient(Environment::Production, $http))->key('app-token', 'kid-1');

        self::assertSame('-----BEGIN PUBLIC KEY-----abc-----END PUBLIC KEY-----', $key);
        self::assertSame('https://api.ebay.com/commerce/notification/v1/public_key/kid-1', $http->sent[0]->url);
        self::assertSame('Bearer app-token', $http->sent[0]->headers['Authorization']);
        self::assertSame('notification', CallBudget::family($http->sent[0]->url));
    }

    /** @return array{0: string, 1: string} header and PEM public key */
    private static function signed(string $body): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_sign($body, $raw, $private, OPENSSL_ALGO_SHA1);
        $header = base64_encode((string) json_encode(['alg' => 'ECDSA', 'kid' => 'kid-1', 'signature' => base64_encode($raw), 'digest' => 'SHA1']));

        return [$header, openssl_pkey_get_details($private)['key']];
    }
}
