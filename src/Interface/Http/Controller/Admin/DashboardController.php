<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Admin;

use App\Application\Transfer\Service\SafetyService;
use App\Infrastructure\Persistence\Repository\AccountRepository;
use App\Infrastructure\Persistence\Repository\PlayerRepository;
use App\Infrastructure\Persistence\Repository\TransferRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private AccountRepository $accountRepository,
        private PlayerRepository $playerRepository,
        private TransferRepository $transferRepository,
        private SafetyService $safetyService,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $accounts = $this->accountRepository->findAllOrdered();
        $totalBalance = 0;
        foreach ($accounts as $account) {
            $totalBalance += $account->getBalance() ?? 0;
        }

        return $this->render('admin/dashboard.html.twig', [
            'accountsCount' => count($accounts),
            'favoritesCount' => count($this->playerRepository->findFavorites()),
            'totalBalance' => $totalBalance,
            'recentTransfers' => $this->transferRepository->findRecent(8),
            'safetyService' => $this->safetyService,
        ]);
    }
}
