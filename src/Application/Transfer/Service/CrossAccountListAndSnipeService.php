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
                    if (!is_string($playerName) || $playerName === '') {
                        throw new \RuntimeException('Карточка куплена, но имя игрока не распознано.');
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

            $listed = $this->marketBrowser->sellForMinPrice($session, $resolvedPlayer, $listPrice);
            if (!$listed) {
                throw new \RuntimeException(sprintf(
                    'Не удалось выставить %s на рынок за %s.',
                    $resolvedPlayer->getName(),
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
            ];
        });

        /** @var Player $listedPlayer */
        $listedPlayer = $senderResult['player'];

        usleep(1_500_000);

        $receiverResult = $this->sessionFactory->withAccount($receiver, function ($session) use ($receiver, $listedPlayer, $listPrice): array {
            $coinsBefore = $this->marketBrowser->getCoins($session);
            $sniped = $this->marketBrowser->snipePlayer($session, $listedPlayer, $receiver->getPlatform(), $listPrice);
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
}
