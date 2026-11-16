<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\Entity;

use App\Domain\Transfer\Enum\LoginStatus;
use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testNewAccountIsActiveWithUnknownLoginStatus(): void
    {
        $account = new Account('player@example.com', Platform::Ps);

        self::assertTrue($account->isActive());
        self::assertSame(LoginStatus::Unknown, $account->getLoginStatus());
        self::assertNull($account->getLastLoginAt());
        self::assertSame(0, $account->getDailySalesCount());
        self::assertNull($account->getDailySalesDate());
        self::assertNull($account->getCooldownUntil());
        self::assertSame(Platform::Ps, $account->getPlatform());
    }

    public function testEmailIsNormalisedToLowerCase(): void
    {
        $account = new Account('  Player@Example.COM ', Platform::Pc);

        self::assertSame('player@example.com', $account->getEmail());
    }

    public function testRegisterSaleIncrementsCounterWithinSameDay(): void
    {
        $account = new Account('player@example.com', Platform::Ps);
        $morning = new \DateTimeImmutable('2026-05-01 09:00:00');
        $evening = new \DateTimeImmutable('2026-05-01 22:30:00');

        $account->registerSale($morning);
        $account->registerSale($evening);

        self::assertSame(2, $account->getDailySalesCount());
        self::assertSame('2026-05-01', $account->getDailySalesDate()?->format('Y-m-d'));
        self::assertSame('00:00:00', $account->getDailySalesDate()?->format('H:i:s'));
    }

    public function testRegisterSaleResetsCounterOnNewDay(): void
    {
        $account = new Account('player@example.com', Platform::Ps);
        $account->registerSale(new \DateTimeImmutable('2026-05-01 09:00:00'));
        $account->registerSale(new \DateTimeImmutable('2026-05-01 10:00:00'));

        $account->registerSale(new \DateTimeImmutable('2026-05-02 00:05:00'));

        self::assertSame(1, $account->getDailySalesCount());
        self::assertSame('2026-05-02', $account->getDailySalesDate()?->format('Y-m-d'));
    }

    public function testSetCredentialsNormalisesTotpSecretAndEmptyValues(): void
    {
        $account = new Account('player@example.com', Platform::Xbox);

        $account->setCredentials('secret', '  jbswy3dpehpk3pxp ', 'http://user:pass@proxy:8080');

        self::assertSame('secret', $account->getPassword());
        self::assertSame('JBSWY3DPEHPK3PXP', $account->getTotpSecret());
        self::assertSame('http://user:pass@proxy:8080', $account->getProxyUrl());

        $account->setCredentials('', '', '');

        self::assertNull($account->getPassword());
        self::assertNull($account->getTotpSecret());
        self::assertNull($account->getProxyUrl());
    }

    public function testRequirePasswordThrowsWhenMissing(): void
    {
        $account = new Account('player@example.com', Platform::Ps);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('player@example.com');

        $account->requirePassword();
    }

    public function testRequireTotpSecretThrowsWhenMissing(): void
    {
        $account = new Account('player@example.com', Platform::Ps);

        $this->expectException(\RuntimeException::class);

        $account->requireTotpSecret();
    }

    public function testRequireAccessorsReturnStoredValues(): void
    {
        $account = new Account('player@example.com', Platform::Ps);
        $account->setCredentials('pwd', 'JBSWY3DPEHPK3PXP', null);

        self::assertSame('pwd', $account->requirePassword());
        self::assertSame('JBSWY3DPEHPK3PXP', $account->requireTotpSecret());
    }

    public function testMarkLoginOkClearsPreviousError(): void
    {
        $account = new Account('player@example.com', Platform::Ps);
        $account->markLoginFailed('captcha');

        self::assertSame(LoginStatus::Failed, $account->getLoginStatus());
        self::assertSame('captcha', $account->getLastLoginError());

        $account->markLoginOk();

        self::assertSame(LoginStatus::Ok, $account->getLoginStatus());
        self::assertNull($account->getLastLoginError());
        self::assertNotNull($account->getLastLoginAt());
    }

    public function testMarkSessionExpiredKeepsOptionalError(): void
    {
        $account = new Account('player@example.com', Platform::Ps);

        $account->markSessionExpired('сессия не поднялась');
        self::assertSame(LoginStatus::Expired, $account->getLoginStatus());
        self::assertSame('сессия не поднялась', $account->getLastLoginError());

        $account->markSessionExpired();
        self::assertNull($account->getLastLoginError());
    }

    public function testMarkLoginUnknownStoresNote(): void
    {
        $account = new Account('player@example.com', Platform::Ps);

        $account->markLoginUnknown('профиль очищен');

        self::assertSame(LoginStatus::Unknown, $account->getLoginStatus());
        self::assertSame('профиль очищен', $account->getLastLoginError());
    }

    public function testLoginErrorIsTruncatedToTwoThousandCharacters(): void
    {
        $account = new Account('player@example.com', Platform::Ps);

        $account->markLoginFailed(str_repeat('я', 2_500));

        self::assertSame(2_000, mb_strlen((string) $account->getLastLoginError()));
    }

    public function testApplyCooldownStoresDeadline(): void
    {
        $account = new Account('player@example.com', Platform::Ps);
        $until = new \DateTimeImmutable('2026-05-02 10:00:00');

        $account->applyCooldown($until);

        self::assertSame($until->format('c'), $account->getCooldownUntil()?->format('c'));
    }

    public function testDailySalesLimitIsFour(): void
    {
        self::assertSame(4, Account::DAILY_SALES_LIMIT);
    }
}
