<?php

declare(strict_types=1);

namespace App\Application\Transfer\Service;

final class MathService
{
    public const EA_TAX_RATE = 0.05;

    /**
     * Рассчитывает суммы листинга для каждого шага перевода с учётом комиссии EA (5%).
     *
     * @return list<array{step: int, listPrice: int, netProceeds: int}>
     */
    public function calculateRequiredAmounts(int $targetAmount, int $maxStepSize = 500_000): array
    {
        if ($targetAmount <= 0) {
            return [];
        }

        $steps = [];
        $remaining = $targetAmount;
        $stepNumber = 1;

        while ($remaining > 0) {
            $chunk = min($remaining, $maxStepSize);
            $listPrice = $this->grossUpForTax($chunk);
            $netProceeds = $this->applyTax($listPrice);

            $steps[] = [
                'step' => $stepNumber,
                'listPrice' => $listPrice,
                'netProceeds' => $netProceeds,
            ];

            $remaining -= $netProceeds;
            ++$stepNumber;
        }

        return $steps;
    }

    public function applyTax(int $listPrice): int
    {
        return (int) floor($listPrice * (1 - self::EA_TAX_RATE));
    }

    public function grossUpForTax(int $desiredNetProceeds): int
    {
        return (int) ceil($desiredNetProceeds / (1 - self::EA_TAX_RATE));
    }

    public function estimateProfit(int $buyPrice, int $sellPrice): int
    {
        return $this->applyTax($sellPrice) - $buyPrice;
    }
}
