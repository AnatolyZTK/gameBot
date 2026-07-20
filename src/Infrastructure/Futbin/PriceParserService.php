<?php

declare(strict_types=1);

namespace App\Infrastructure\Futbin;

use App\Domain\Transfer\ValueObject\PlayerPriceQuote;
use App\Infrastructure\Parser\FutbinJsonPriceParser;
use App\Infrastructure\Parser\FutbinPlayerPageParser;
use App\Infrastructure\Persistence\Entity\Player;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PriceParserService
{
    public function __construct(
        private FutbinHttpClient $httpClient,
        private FutbinBrowserFetcher $browserFetcher,
        private FutbinJsonPriceParser $jsonParser,
        private FutbinPlayerPageParser $htmlParser,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'monolog.logger.scraping')]
        private LoggerInterface $logger,
        private string $gameVersion,
    ) {
    }

    public function syncPlayer(int $futbinId): ?Player
    {
        $quote = $this->fetchQuote($futbinId);
        if ($quote === null) {
            return null;
        }

        return $this->upsertQuote($quote);
    }

    /**
     * @param list<Player> $players
     *
     * @return array{updated: int, failed: int}
     */
    public function syncPlayers(array $players): array
    {
        $updated = 0;
        $failed = 0;

        foreach ($players as $player) {
            try {
                $result = $this->syncPlayer($player->getFutbinId());
                if ($result === null) {
                    ++$failed;
                    continue;
                }

                ++$updated;
            } catch (\Throwable $exception) {
                ++$failed;
                $this->logger->error('Futbin price sync failed', [
                    'futbinId' => $player->getFutbinId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    public function fetchQuote(int $futbinId): ?PlayerPriceQuote
    {
        $sourceUrl = sprintf(
            'https://www.futbin.com/%s/player/%d',
            $this->gameVersion,
            $futbinId,
        );

        try {
            $json = $this->httpClient->fetchPlayerPrices($futbinId, $this->gameVersion);
            $quote = $this->jsonParser->parse($json, $futbinId, $sourceUrl);
            if ($quote !== null) {
                return $quote;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Futbin HTTP fetch failed, trying browser', [
                'futbinId' => $futbinId,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $browserPayload = $this->browserFetcher->fetchPlayerPrices($futbinId);
            if ($browserPayload === null) {
                return null;
            }

            if (str_starts_with(ltrim($browserPayload), '{')) {
                return $this->jsonParser->parse($browserPayload, $futbinId, $sourceUrl);
            }

            return $this->htmlParser->parse($browserPayload, $sourceUrl);
        } catch (\Throwable $exception) {
            $this->logger->error('Futbin browser fetch failed', [
                'futbinId' => $futbinId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function upsertQuote(PlayerPriceQuote $quote): Player
    {
        $player = $this->playerRepository->findByFutbinId($quote->futbinId);
        $now = new \DateTimeImmutable();

        if ($player === null) {
            $player = new Player(
                futbinId: $quote->futbinId,
                name: $quote->name,
                futbinUrl: $quote->sourceUrl,
            );
            $this->entityManager->persist($player);
        }

        $player->updatePrices(
            rating: $quote->rating,
            position: $quote->position,
            pricePs: $quote->pricePs,
            priceXbox: $quote->priceXbox,
            pricePc: $quote->pricePc,
            updatedAt: $now,
        );

        $this->entityManager->flush();

        return $player;
    }
}
