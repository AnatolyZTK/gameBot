<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Admin;

use App\Infrastructure\Persistence\Entity\Player;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class PlayerController extends AbstractController
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/admin/players', name: 'admin_players', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/players/index.html.twig', [
            'players' => $this->playerRepository->findAllOrdered(),
        ]);
    }

    #[Route('/admin/players/add', name: 'admin_players_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('player_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $futbinId = (int) $request->request->get('futbin_id');
        if ($futbinId <= 0) {
            $this->addFlash('error', 'Укажите futbin_id');

            return $this->redirectToRoute('admin_players');
        }

        $player = $this->playerRepository->findByFutbinId($futbinId);
        $name = trim((string) $request->request->get('name'));
        $favorite = $request->request->getBoolean('favorite');

        if (!$player instanceof Player) {
            if ($name === '') {
                $name = 'Player '.$futbinId;
            }
            $player = new Player(
                futbinId: $futbinId,
                name: $name,
                futbinUrl: 'https://www.futbin.com/player/'.$futbinId,
            );
            $this->entityManager->persist($player);
        } elseif ($name !== '') {
            // имя только при создании; для существующего не трогаем без setter
        }

        $player->setFavorite($favorite);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Игрок %s (futbin:%d) сохранён', $player->getName(), $futbinId));

        return $this->redirectToRoute('admin_players');
    }

    #[Route('/admin/players/{id}/favorite', name: 'admin_players_favorite', methods: ['POST'])]
    public function toggleFavorite(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $player = $this->playerRepository->find(Uuid::fromString($id));
        if (!$player instanceof Player) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('player_fav_'.$player->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $player->setFavorite(!$player->isFavorite());
        $this->entityManager->flush();

        $this->addFlash('success', $player->getName().': избранное '.($player->isFavorite() ? 'вкл' : 'выкл'));

        return $this->redirectToRoute('admin_players');
    }
}
