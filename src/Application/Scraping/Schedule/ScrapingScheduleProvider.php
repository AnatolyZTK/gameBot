<?php

declare(strict_types=1);

namespace App\Application\Scraping\Schedule;

use App\Application\Scraping\Message\ScrapePageMessage;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

#[AsSchedule]
final class ScrapingScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::every(
                    '15 minutes',
                    new RedispatchMessage(new ScrapePageMessage('/catalog'), 'async'),
                ),
            )
            ->add(
                RecurringMessage::trigger(
                    CronExpressionTrigger::fromSpec('0 3 * * *'),
                    new RedispatchMessage(new ScrapePageMessage('/catalog/full'), 'async'),
                ),
                'nightly-full-scrape',
            )
            ->add(
                RecurringMessage::every(
                    '10 minutes',
                    new RunCommandMessage('app:sync:prices --favorites-only'),
                ),
                'futbin-price-sync',
            );
    }
}
