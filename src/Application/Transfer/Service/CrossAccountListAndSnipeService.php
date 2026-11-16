<?php

declare(strict_types=1);

namespace App\Application\Transfer\Service;

use App\Infrastructure\Browser\Fut\FutTransferMarketBrowser;
use App\Infrastructure\Browser\Fut\FutWebAppSessionFactory;
use App\Infrastructure\Persistence\Entity\Account;
use App\Infrastructure\Persistence\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Продажа карточки на sender и мгновенный снайп на receiver.
 */
final class CrossAccountListAndSnipeService
{
    private const PLACEHOLDER_NAME = 'Bought card';

    /** Доля выручки после налога EA (5%). */
    private const NET_RATIO = 0.95;

    /** Допустимое занижение выручки при проверке баланса. */
    private const NET_TOLERANCE = 0.9;

    public function __construct(
        private FutWebAppSessionFactory $sessionFactory,
        private FutTransferMarketBrowser $marketBrowser,
        private EntityManagerInterface $entityManager,
        private PlayerRepositoryResolver $playerResolver,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *   senderCoinsBefore: ?int,
     *   senderCoinsAfter: ?int,
     *   receiverCoinsBefore: ?int,
     *   receiverCoinsAfter: ?int,
     *   bought: bool,
     *   listed: bool,
     *   sniped: bool,
     *   listPrice: int,
     *   playerName: string
     * }
     */
    public function listAndSnipe(
        Account $sender,
        Account $receiver,
        ?Player $player,
        int $buyMaxPrice,
        int $listPrice,
        bool $buyFirst = true,
        bool $buyAny = false,
    ): array {
        if ($sender->getId()->equals($receiver->getId())) {
            throw new \InvalidArgumentException('Sender и receiver должны быть разными аккаунтами.');
        }

        $this->logger->info('Cross-account list+snipe started', [
            'sender' => $sender->getEmail(),
            'receiver' => $receiver->getEmail(),
            'player' => $player?->getName(),
            'buyAny' => $buyAny,
            'listPrice' => $listPrice,
        ]);

        $senderResult = $this->sessionFactory->withAccount($sender, function ($session) use ($sender, $player, $buyMaxPrice, $listPrice, $buyFirst, $buyAny): array {
            $coinsBefore = $this->marketBrowser->getCoins($session);
            $bought = false;
            $playerName = $player?->getName();
            $resolvedPlayer = $player;

            if ($buyFirst) {
                if ($buyAny || $player === null) {
                    $buyResult = $this->marketBrowser->buyAnyUnderPrice($session, $buyMaxPrice);
                    $bought = $buyResult['bought'];
                    $playerName = $buyResult['playerName'] ?? $playerName;
                    if (!$bought) {
                        throw new \RuntimeException(sprintf(
                            'Не удалось купить любую карточку до %s coins на sender.',
                            number_format($buyMaxPrice, 0, '.', ' '),
                        ));
                    }
                    // Имя может быть пустым — выставим карточку с экрана покупки, имя дочитаем после
                    if (!is_string($playerName) || $playerName === '') {
                        $playerName = self::PLACEHOLDER_NAME;
                    }
                    $resolvedPlayer = $this->playerResolver->resolveOrCreateByName($playerName);
                } else {
                    $bought = $this->marketBrowser->buyPlayer($session, $player, $sender->getPlatform(), $buyMaxPrice);
                    if (!$bought) {
                        throw new \RuntimeException(sprintf(
                            'Не удалось купить %s на sender до %s coins.',
                            $player->getName(),
                            number_format($buyMaxPrice, 0, '.', ' '),
                        ));
                    }
                    $resolvedPlayer = $player;
                    $playerName = $player->getName();
                }
                usleep(2_000_000);
            }

            if (!$resolvedPlayer instanceof Player) {
                throw new \RuntimeException('Игрок для листинга не определён.');
            }

            $listPrice = MathService::roundUpToValidBin($listPrice);

            $listed = $this->marketBrowser->sellForMinPrice($session, $resolvedPlayer, $listPrice);
            if (!$listed) {
                throw new \RuntimeException(sprintf(
                    'Не удалось выставить %s на рынок за %s.',
                    $resolvedPlayer->getName(),
                    number_format($listPrice, 0, '.', ' '),
                ));
            }

            // Уточнить имя после листинга (если было placeholder)
            $betterName = $this->marketBrowser->readListedOrSelectedName($session);
            if (is_string($betterName) && $betterName !== '' && $betterName !== $resolvedPlayer->getName()) {
                $resolvedPlayer = $this->playerResolver->resolveOrCreateByName($betterName);
                $playerName = $betterName;
            }

            if (trim((string) $playerName) === "" || strcasecmp((string) $playerName, self::PLACEHOLDER_NAME) === 0) {
                throw new \RuntimeException(sprintf(
                    'Не удалось определить имя выставленной карточки — снайп отменён, чтобы не купить чужой лот. Карточка осталась в Transfer List у %s (цена %s).',
                    $sender->getEmail(),
                    number_format($listPrice, 0, '.', ' '),
                ));
            }

            $coinsAfter = $this->marketBrowser->getCoins($session);
            $sender->setBalance($coinsAfter);
            $this->entityManager->flush();

            return [
                'coinsBefore' => $coinsBefore,
                'coinsAfter' => $coinsAfter,
                'bought' => $bought,
                'listed' => $listed,
                'player' => $resolvedPlayer,
                'playerName' => $resolvedPlayer->getName(),
                'listPrice' => $listPrice,
            ];
        });

        /** @var Player $listedPlayer */
        $listedPlayer = $senderResult['player'];
        $listPrice = (int) ($senderResult['listPrice'] ?? MathService::roundUpToValidBin($listPrice));

        // Дать рынку время проиндексировать лот
        usleep(3_500_000);

        $receiverResult = $this->sessionFactory->withAccount($receiver, function ($session) use ($receiver, $listedPlayer, $listPrice): array {
            $coinsBefore = $this->marketBrowser->getCoins($session);
            $sniped = $this->marketBrowser->snipePlayer($session, $listedPlayer, $receiver->getPlatform(), $listPrice, true);
            if (!$sniped) {
                throw new \RuntimeException(sprintf(
                    'Не удалось снайпить %s на receiver по цене %s.',
                    $listedPlayer->getName(),
                    number_format($listPrice, 0, '.', ' '),
                ));
            }

            $coinsAfter = $this->marketBrowser->getCoins($session);
            $receiver->setBalance($coinsAfter);
            $this->entityManager->flush();

            return [
                'coinsBefore' => $coinsBefore,
                'coinsAfter' => $coinsAfter,
                'sniped' => $sniped,
            ];
        });

        $this->assertListerReceivedCoins($sender, $senderResult['coinsAfter'] ?? null, $listPrice);

        return [
            'senderCoinsBefore' => $senderResult['coinsBefore'],
            'senderCoinsAfter' => $senderResult['coinsAfter'],
            'receiverCoinsBefore' => $receiverResult['coinsBefore'],
            'receiverCoinsAfter' => $receiverResult['coinsAfter'],
            'bought' => $senderResult['bought'],
            'listed' => $senderResult['listed'],
            'sniped' => $receiverResult['sniped'],
            'listPrice' => $listPrice,
            'playerName' => (string) $senderResult['playerName'],
        ];
    }

    /**
     * Проверяет, что у lister реально выросли монеты: иначе sniper купил не наш лот.
     */
    private function assertListerReceivedCoins(Account $lister, ?int $coinsAfterListing, int $listPrice): void
    {
        $finalCoins = $this->sessionFactory->withAccount(
            $lister,
            fn ($session): ?int => $this->marketBrowser->getCoins($session),
        );

        if ($finalCoins === null || $coinsAfterListing === null) {
            $this->logger->warning('Не удалось проверить баланс lister после снайпа', [
                'lister' => $lister->getEmail(),
            ]);

            return;
        }

        $expectedNet = (int) floor($listPrice * self::NET_RATIO);
        $received = $finalCoins - $coinsAfterListing;

        $lister->setBalance($finalCoins);
        $this->entityManager->flush();

        $this->logger->info('Проверка выручки lister', [
            'lister' => $lister->getEmail(),
            'coinsAfterListing' => $coinsAfterListing,
            'coinsFinal' => $finalCoins,
            'received' => $received,
            'expectedNet' => $expectedNet,
        ]);

        if ($received < (int) floor($expectedNet * self::NET_TOLERANCE)) {
            throw new \RuntimeException(sprintf(
                'Монеты не дошли до %s: ожидалось ~%s, баланс вырос на %s. Вероятно, sniper купил чужой лот, либо карточка ещё в продаже.',
                $lister->getEmail(),
                number_format($expectedNet, 0, '.', ' '),
                number_format($received, 0, '.', ' '),
            ));
        }
    }
}
