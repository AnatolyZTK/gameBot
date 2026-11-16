<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Transfer\Enum;

use App\Domain\Transfer\Enum\TransferStatus;
use PHPUnit\Framework\TestCase;

final class TransferStatusTest extends TestCase
{
    public function testStoredValuesMatchDatabaseColumn(): void
    {
        self::assertSame(
            ['planned', 'executing', 'completed', 'failed'],
            array_map(static fn (TransferStatus $s): string => $s->value, TransferStatus::cases()),
        );
    }

    /**
     * TransferRepository::countPairTransfersSince фильтрует по сырым строкам
     * ['executing', 'completed'] — они должны совпадать со значениями enum,
     * иначе лимит «одна пара в сутки» молча перестанет работать.
     */
    public function testStatusesUsedInPairLimitQueryExist(): void
    {
        self::assertSame(TransferStatus::Executing, TransferStatus::from('executing'));
        self::assertSame(TransferStatus::Completed, TransferStatus::from('completed'));
    }
}
