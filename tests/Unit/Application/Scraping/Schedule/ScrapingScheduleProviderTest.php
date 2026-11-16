<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scraping\Schedule;

use App\Application\Scraping\Message\ScrapePageMessage;
use App\Application\Scraping\Schedule\ScrapingScheduleProvider;
use Cron\CronExpression;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Exception\LogicException;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

/**
 * Расписание использует CronExpressionTrigger, а dragonmantank/cron-expression
 * в composer.json не заявлен. Пока пакета нет, getSchedule() бросает исключение —
 * это ломает не только ночной парсинг, но и весь `scheduler:consume`.
 *
 * Тесты содержимого расписания скипаются, пока зависимость не установлена
 * (`composer require dragonmantank/cron-expression`), и начинают проверять
 * реальные триггеры сразу после установки.
 */
final class ScrapingScheduleProviderTest extends TestCase
{
    public function testScheduleCannotBeBuiltWithoutCronExpressionPackage(): void
    {
        if (class_exists(CronExpression::class)) {
            self::markTestSkipped('dragonmantank/cron-expression установлен — расписание строится.');
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/cron expression.*not installed/');

        (new ScrapingScheduleProvider())->getSchedule();
    }

    public function testScheduleHasThreeRecurringMessages(): void
    {
        self::assertCount(3, $this->recurringMessages());
    }

    public function testCatalogIsScrapedEveryFifteenMinutesThroughAsyncTransport(): void
    {
        $recurring = $this->recurringMessages()[0];
        $message = $this->singleMessage($recurring);

        self::assertInstanceOf(RedispatchMessage::class, $message);
        self::assertSame(['async'], $message->transportNames);
        self::assertInstanceOf(ScrapePageMessage::class, $message->envelope);
        self::assertSame('/catalog', $message->envelope->path);
        self::assertStringContainsString('15 minute', (string) $recurring->getTrigger());
    }

    public function testNightlyFullScrapeRunsAtThreeAmByCron(): void
    {
        $recurring = $this->recurringMessages()[1];
        $message = $this->singleMessage($recurring);

        self::assertInstanceOf(CronExpressionTrigger::class, $recurring->getTrigger());
        self::assertSame('0 3 * * *', (string) $recurring->getTrigger());
        self::assertInstanceOf(RedispatchMessage::class, $message);
        self::assertSame(['async'], $message->transportNames);
        self::assertSame('/catalog/full', $message->envelope->path);
    }

    public function testFutbinPricesAreSyncedEveryTenMinutesForFavouritesOnly(): void
    {
        $recurring = $this->recurringMessages()[2];
        $message = $this->singleMessage($recurring);

        self::assertInstanceOf(RunCommandMessage::class, $message);
        self::assertSame('app:sync:prices --favorites-only', $message->input);
        self::assertStringContainsString('10 minute', (string) $recurring->getTrigger());
    }

    public function testNamedSchedulesKeepTheirIdentifiers(): void
    {
        $ids = array_map(
            static fn (RecurringMessage $message): string => $message->getId(),
            $this->recurringMessages(),
        );

        self::assertContains('nightly-full-scrape', $ids);
        self::assertContains('futbin-price-sync', $ids);
    }

    /** @return list<RecurringMessage> */
    private function recurringMessages(): array
    {
        if (!class_exists(CronExpression::class)) {
            self::markTestSkipped(
                'Расписание не строится: нет dragonmantank/cron-expression. '
                .'Установите пакет — тест начнёт проверять триггеры.',
            );
        }

        return array_values((new ScrapingScheduleProvider())->getSchedule()->getRecurringMessages());
    }

    private function singleMessage(RecurringMessage $recurring): object
    {
        $messages = $recurring->getMessages();

        self::assertCount(1, $messages);

        return $messages[0];
    }
}
