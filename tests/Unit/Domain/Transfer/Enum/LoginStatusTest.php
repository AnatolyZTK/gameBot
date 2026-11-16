<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer\Enum;

use App\Domain\Transfer\Enum\LoginStatus;
use PHPUnit\Framework\TestCase;

final class LoginStatusTest extends TestCase
{
    public function testStoredValuesMatchDatabaseColumn(): void
    {
        self::assertSame(
            ['unknown', 'ok', 'failed', 'expired'],
            array_map(static fn (LoginStatus $s): string => $s->value, LoginStatus::cases()),
        );
    }

    public function testEveryCaseHasRussianLabelForAdminUi(): void
    {
        foreach (LoginStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }

        self::assertSame('не проверен', LoginStatus::Unknown->label());
        self::assertSame('сессия истекла', LoginStatus::Expired->label());
    }
}
