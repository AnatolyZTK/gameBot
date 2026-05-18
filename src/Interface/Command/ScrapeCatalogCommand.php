<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Application\Scraping\Message\ScrapePageMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:scrape:catalog',
    description: 'Поставить в очередь парсинг каталога игрового сайта',
)]
final class ScrapeCatalogCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Путь на сайте', '/catalog')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Выполнить синхронно без очереди');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('path');

        $this->bus->dispatch(new ScrapePageMessage($path));

        $io->success(sprintf('Задача парсинга поставлена в очередь: %s', $path));

        return Command::SUCCESS;
    }
}
