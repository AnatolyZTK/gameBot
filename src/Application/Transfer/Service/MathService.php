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
        $raw = (int) ceil($desiredNetProceeds / (1 - self::EA_TAX_RATE));
        $rounded = self::roundUpToValidBin($raw);

        // После округления net должен покрывать целевую сумму
        while ($this->applyTax($rounded) < $desiredNetProceeds) {
            $rounded = self::roundUpToValidBin($rounded + 1);
        }

        return $rounded;
    }

    public function estimateProfit(int $buyPrice, int $sellPrice): int
    {
        return $this->applyTax($sellPrice) - $buyPrice;
    }

    /**
     * Шаг цены Buy Now на Transfer Market (EA FUT).
     */
    public static function binStep(int $price): int
    {
        $price = max(0, $price);

        return match (true) {
            $price < 1_000 => 50,
            $price < 10_000 => 100,
            $price < 50_000 => 250,
            $price < 100_000 => 500,
            $price < 250_000 => 1_000,
            $price < 500_000 => 2_500,
            default => 5_000,
        };
    }

    /**
     * Ближайшая валидная BIN-цена (вниз при равной дистанции).
     */
    public static function roundToValidBin(int $price): int
    {
        $price = max(150, $price);
        $step = self::binStep($price);
        $rounded = (int) (round($price / $step) * $step);

        // round() мог попасть в другой bracket — пересчитать шаг
        $step = self::binStep(max(150, $rounded));
        $rounded = (int) (round($price / $step) * $step);

        return max(150, $rounded);
    }

    /**
     * Минимальная валидная BIN ≥ price.
     */
    public static function roundUpToValidBin(int $price): int
    {
        $price = max(150, $price);
        $step = self::binStep($price);
        $rounded = (int) (ceil($price / $step) * $step);
        $step = self::binStep(max(150, $rounded));
        $rounded = (int) (ceil($price / $step) * $step);

        return max(150, $rounded);
    }
}
