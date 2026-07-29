<?php

declare(strict_types=1);

namespace App\Interface\Command;

use App\Application\Transfer\Service\AccountIdentifierResolver;
use App\Infrastructure\Browser\Fut\FutWebAppSessionFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:fut:market-debug', description: 'Диагностика UI трансферного рынка')]
final class FutMarketDebugCommand extends Command
{
    public function __construct(
        private AccountIdentifierResolver $accountResolver,
        private FutWebAppSessionFactory $sessionFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email')
            ->addOption('max-price', null, InputOption::VALUE_REQUIRED, 'Max buy now', '3000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getOption('email');
        if (!is_string($email) || $email === '') {
            $io->error('--email required');

            return Command::FAILURE;
        }
        $maxPrice = (int) $input->getOption('max-price');
        $account = $this->accountResolver->resolve($email);

        $dump = $this->sessionFactory->withAccount($account, function ($session) use ($maxPrice): array {
            $client = $session->client();
            $factory = $session->factory();
            $factory->dismissBlockingOverlays($client);

            $tab = null;
            try {
                $factory->clickCssSelector($client, '.ut-tab-bar-item.icon-transfer');
                $tab = 'clicked via WebDriver';
            } catch (\Throwable $e) {
                $tab = 'click failed: '.$e->getMessage();
            }
            usleep(3_000_000);
            $factory->waitForInteractive($client);
            $factory->dismissBlockingOverlays($client);

            $hub = $client->executeScript(<<<'JS'
return {
  activeTab: Array.from(document.querySelectorAll('.ut-tab-bar-item')).map((el) => ({
    text: (el.innerText || '').trim(),
    cls: el.className,
    selected: el.classList.contains('selected') || el.getAttribute('aria-selected') === 'true',
  })),
  tiles: Array.from(document.querySelectorAll('[class*=tile]')).slice(0, 40).map((el) => ({
    cls: String(el.className).slice(0, 160),
    text: (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 140),
  })),
};
JS);

            $opened = null;
            try {
                $factory->clickCssSelector($client, '.ut-tile-transfer-market');
                $opened = ['via' => 'WebDriver'];
            } catch (\Throwable $e) {
                $opened = ['error' => $e->getMessage()];
            }
            usleep(3_000_000);
            $factory->waitForInteractive($client);

            $filters = $client->executeScript(<<<'JS'
return {
  inputs: Array.from(document.querySelectorAll('input')).filter((i) => i.offsetParent).map((i) => ({
    cls: String(i.className).slice(0, 120),
    ph: i.placeholder || '',
    val: i.value,
    type: i.type,
  })),
  buttons: Array.from(document.querySelectorAll('button')).filter((b) => b.offsetParent).map((b) => (b.innerText || '').replace(/\s+/g, ' ').trim()).filter(Boolean).slice(0, 40),
  snippet: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 800),
};
JS);

            $search = $client->executeScript(<<<'JS'
const [maxPrice] = arguments;
const inputs = Array.from(document.querySelectorAll('input.ut-number-input-control')).filter((i) => i.offsetParent);
const setNative = (input, value) => {
  input.focus();
  const descriptor = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
  descriptor.set.call(input, String(value));
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
};
if (inputs.length >= 4) {
  setNative(inputs[2], 200);
  setNative(inputs[3], maxPrice);
}
return {
  values: inputs.map((i) => i.value),
  searchButtons: Array.from(document.querySelectorAll('button')).filter((b) => b.offsetParent && /search/i.test(b.innerText||'')).map((b) => ({
    text: (b.innerText||'').trim(),
    cls: b.className,
  })),
};
JS, [$maxPrice]);

            try {
                $factory->clickCssSelector($client, 'button.btn-standard.call-to-action');
                $search['clicked'] = 'call-to-action';
            } catch (\Throwable $e) {
                $clicked = $client->executeScript(<<<'JS'
const btn = Array.from(document.querySelectorAll('button')).find((b) => /^search$/i.test((b.innerText||'').trim()));
btn?.click();
return btn ? btn.className : null;
JS);
                $search['clicked'] = $clicked;
                $search['ctaError'] = $e->getMessage();
            }
            usleep(5_000_000);
            $factory->waitForInteractive($client);

            $after = $client->executeScript(<<<'JS'
return {
  items: document.querySelectorAll('.listFUTItem').length,
  snippet: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 600),
};
JS);

            return [
                'tab' => $tab,
                'hub' => $hub,
                'opened' => $opened,
                'filters' => $filters,
                'search' => $search,
                'after' => $after,
            ];
        });

        $io->writeln(json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
