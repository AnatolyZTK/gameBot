<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Transfer\Service;

use App\Application\Transfer\Service\MathService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MathServiceTest extends TestCase
{
    private MathService $mathService;

    protected function setUp(): void
    {
        $this->mathService = new MathService();
    }

    public function testApplyTaxTakesFivePercentAndFloors(): void
    {
        self::assertSame(950, $this->mathService->applyTax(1_000));
        self::assertSame(9_500, $this->mathService->applyTax(10_000));
        // 175 * 0.95 = 166.25 → floor
        self::assertSame(166, $this->mathService->applyTax(175));
        self::assertSame(0, $this->mathService->applyTax(0));
    }

    /**
     * Главное свойство gross-up: после вычета 5% на руках должно остаться
     * не меньше желаемой суммы, а сама цена — валидной BIN.
     */
    #[DataProvider('desiredNetProceedsProvider')]
    public function testGrossUpForTaxCoversDesiredNetProceeds(int $desiredNet): void
    {
        $listPrice = $this->mathService->grossUpForTax($desiredNet);

        self::assertGreaterThanOrEqual(
            $desiredNet,
            $this->mathService->applyTax($listPrice),
            sprintf('Листинг %d не покрывает %d после налога', $listPrice, $desiredNet),
        );
        self::assertSame(
            $listPrice,
            MathService::roundUpToValidBin($listPrice),
            'Цена листинга должна быть валидной BIN-ценой',
        );
    }

    /** @return iterable<string, array{int}> */
    public static function desiredNetProceedsProvider(): iterable
    {
        yield '150' => [150];
        yield '1 000' => [1_000];
        yield '9 999' => [9_999];
        yield '47 500' => [47_500];
        yield '100 000' => [100_000];
        yield '250 001' => [250_001];
        yield '500 000' => [500_000];
        yield '1 234 567' => [1_234_567];
    }

    public function testGrossUpForTaxConcreteValue(): void
    {
        // 1000 / 0.95 = 1052.6… → 1053 → округление вверх до шага 100 → 1100
        self::assertSame(1_100, $this->mathService->grossUpForTax(1_000));
        self::assertSame(1_045, $this->mathService->applyTax(1_100));
    }

    public function testCalculateRequiredAmountsReturnsNothingForNonPositiveTarget(): void
    {
        self::assertSame([], $this->mathService->calculateRequiredAmounts(0));
        self::assertSame([], $this->mathService->calculateRequiredAmounts(-100));
    }

    public function testCalculateRequiredAmountsFitsSmallTargetInOneStep(): void
    {
        $steps = $this->mathService->calculateRequiredAmounts(1_000);

        self::assertCount(1, $steps);
        self::assertSame(['step' => 1, 'listPrice' => 1_100, 'netProceeds' => 1_045], $steps[0]);
    }

    public function testCalculateRequiredAmountsSplitsByMaxStepSize(): void
    {
        $steps = $this->mathService->calculateRequiredAmounts(600_000);

        self::assertCount(2, $steps);
        self::assertSame([1, 2], array_column($steps, 'step'));

        $totalNet = array_sum(array_column($steps, 'netProceeds'));
        self::assertGreaterThanOrEqual(600_000, $totalNet);

        foreach ($steps as $step) {
            self::assertGreaterThan(0, $step['listPrice']);
            self::assertSame($step['listPrice'], MathService::roundUpToValidBin($step['listPrice']));
        }
    }

    public function testCalculateRequiredAmountsHonoursCustomMaxStepSize(): void
    {
        $steps = $this->mathService->calculateRequiredAmounts(100_000, 25_000);

        self::assertGreaterThanOrEqual(4, \count($steps));
        self::assertGreaterThanOrEqual(
            100_000,
            array_sum(array_column($steps, 'netProceeds')),
        );
    }

    #[DataProvider('binStepProvider')]
    public function testBinStepMatchesEaPriceLadder(int $price, int $expectedStep): void
    {
        self::assertSame($expectedStep, MathService::binStep($price));
    }

    /** @return iterable<string, array{int, int}> */
    public static function binStepProvider(): iterable
    {
        yield 'ниже 1k' => [999, 50];
        yield 'ровно 1k' => [1_000, 100];
        yield 'ниже 10k' => [9_999, 100];
        yield 'ровно 10k' => [10_000, 250];
        yield 'ниже 50k' => [49_999, 250];
        yield 'ровно 50k' => [50_000, 500];
        yield 'ровно 100k' => [100_000, 1_000];
        yield 'ровно 250k' => [250_000, 2_500];
        yield 'ровно 500k' => [500_000, 5_000];
        yield 'миллион' => [1_000_000, 5_000];
        yield 'отрицательная цена' => [-100, 50];
    }

    #[DataProvider('roundUpProvider')]
    public function testRoundUpToValidBinNeverGoesBelowRequestedPrice(int $price, int $expected): void
    {
        self::assertSame($expected, MathService::roundUpToValidBin($price));
    }

    /** @return iterable<string, array{int, int}> */
    public static function roundUpProvider(): iterable
    {
        yield 'ниже минимума' => [1, 150];
        yield 'минимум' => [150, 150];
        yield 'внутри шага 50' => [151, 200];
        yield 'переход через 1k' => [999, 1_000];
        yield 'внутри шага 100' => [1_001, 1_100];
        yield 'внутри шага 250' => [10_001, 10_250];
        yield 'внутри шага 5000' => [501_000, 505_000];
        yield 'уже валидная' => [10_250, 10_250];
    }

    public function testRoundToValidBinPicksNearestStep(): void
    {
        self::assertSame(150, MathService::roundToValidBin(1));
        self::assertSame(150, MathService::roundToValidBin(160));
        self::assertSame(1_000, MathService::roundToValidBin(1_020));
        self::assertSame(10_250, MathService::roundToValidBin(10_200));
        // Ровно посередине между 150 и 200: PHP round() округляет вверх,
        // хотя docblock метода обещает «вниз при равной дистанции».
        self::assertSame(200, MathService::roundToValidBin(175));
    }

    public function testEstimateProfitSubtractsBuyPriceFromNetProceeds(): void
    {
        // 10 000 продажа → 9 500 после налога, покупка за 8 000
        self::assertSame(1_500, $this->mathService->estimateProfit(8_000, 10_000));
        self::assertSame(-500, $this->mathService->estimateProfit(10_000, 10_000));
    }
}
