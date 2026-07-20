<?php

declare(strict_types=1);

namespace App\Interface\Command\Helper;

use Symfony\Component\Console\Style\SymfonyStyle;

final class TransferPlanRenderer
{
    /**
     * @param array{
     *   receiverId: string,
     *   targetAmount: int,
     *   steps: list<array<string, mixed>>,
     *   senders: list<array<string, mixed>>,
     *   players?: list<array<string, mixed>>,
     *   estimatedProfit: int
     * } $plan
     */
    public function render(SymfonyStyle $io, array $plan, ?string $receiverLabel = null): void
    {
        $io->section('План перевода');
        $io->definitionList(
            ['Получатель' => $receiverLabel ?? $plan['receiverId']],
            ['Сумма' => number_format($plan['targetAmount'], 0, '.', ' ').' coins'],
            ['Оценка прибыли' => number_format($plan['estimatedProfit'], 0, '.', ' ').' coins'],
        );

        $steps = $plan['steps'] ?? [];
        if ($steps !== []) {
            $io->text('Шаги (листинг → net после 5% EA):');
            $io->table(
                ['#', 'List price', 'Net proceeds'],
                array_map(static fn (array $step): array => [
                    (string) ($step['step'] ?? ''),
                    number_format((int) ($step['listPrice'] ?? 0), 0, '.', ' '),
                    number_format((int) ($step['netProceeds'] ?? 0), 0, '.', ' '),
                ], $steps),
            );
        }

        $players = $plan['players'] ?? [];
        if ($players !== []) {
            $io->text('Игроки:');
            $io->table(
                ['Игрок', 'Buy', 'List', 'Net', 'Profit'],
                array_map(static fn (array $player): array => [
                    (string) ($player['name'] ?? ''),
                    number_format((int) ($player['buyPrice'] ?? 0), 0, '.', ' '),
                    number_format((int) ($player['listPrice'] ?? 0), 0, '.', ' '),
                    number_format((int) ($player['expectedNet'] ?? 0), 0, '.', ' '),
                    number_format((int) ($player['estimatedProfit'] ?? 0), 0, '.', ' '),
                ], $players),
            );
        } else {
            $io->warning('Нет избранных игроков с ценами — план пуст. Добавьте игроков и выполните app:sync:prices.');
        }

        $senders = $plan['senders'] ?? [];
        if ($senders === []) {
            $io->warning('Нет доступных отправителей (cooldown, лимит продаж или pair-trade 24h).');
        } else {
            $io->text('Доступные отправители:');
            $io->table(
                ['Email', 'ID', 'Осталось продаж', 'Может отправить'],
                array_map(static fn (array $sender): array => [
                    (string) ($sender['email'] ?? ''),
                    (string) ($sender['id'] ?? ''),
                    (string) ($sender['remainingSales'] ?? ''),
                    isset($sender['canSend']) && $sender['canSend'] ? 'yes' : 'no',
                ], $senders),
            );
        }
    }
}
