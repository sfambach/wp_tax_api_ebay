<?php declare(strict_types=1);

namespace TaxEbay\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxEbay\Core\Notification\AccountDeletion;

final class AccountDeletionTest extends TestCase
{
    public function testChallengeResponseIsTheHashOfCodeTokenEndpointInThisOrder(): void
    {
        self::assertSame(
            hash('sha256', 'abc' . 'token' . 'https://www.fambach.net/wp-json/taxebay/v1/account-deletion'),
            AccountDeletion::challengeResponse('abc', 'token', 'https://www.fambach.net/wp-json/taxebay/v1/account-deletion')
        );
    }

    public function testNewTokenFollowsEbaysRule(): void
    {
        $token = AccountDeletion::newToken();

        self::assertTrue(AccountDeletion::isValidToken($token), $token);
        self::assertFalse(AccountDeletion::isValidToken('short'));
        self::assertFalse(AccountDeletion::isValidToken(str_repeat('a', 40) . '!'));
    }

    public function testReadsTheDeletedAccount(): void
    {
        $body = [
            'metadata'     => ['topic' => 'MARKETPLACE_ACCOUNT_DELETION', 'schemaVersion' => '1.0'],
            'notification' => ['notificationId' => 'n', 'data' => ['username' => 'retro_fan_42', 'userId' => 'ma8vp1jySJC', 'eiasToken' => 'x']],
        ];

        self::assertSame(['username' => 'retro_fan_42', 'user_id' => 'ma8vp1jySJC'], AccountDeletion::deletedAccount($body));
        self::assertNull(AccountDeletion::deletedAccount(['metadata' => ['topic' => 'OTHER']]));
    }
}
