<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller;

use App\Infrastructure\Browser\EaFutDashboardScraper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FutDashboardController extends AbstractController
{
    public function __construct(
        private readonly EaFutDashboardScraper $dashboardScraper,
    ) {
    }

    #[Route('/fut', name: 'fut_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        $email = $_ENV['EA_LOGIN_EMAIL'] ?? $_SERVER['EA_LOGIN_EMAIL'] ?? getenv('EA_LOGIN_EMAIL');
        if (!is_string($email) || $email === '') {
            return $this->render('fut/dashboard.html.twig', [
                'dashboard' => null,
                'accountEmail' => null,
                'error' => 'Задайте переменную окружения EA_LOGIN_EMAIL.',
            ]);
        }

        $dashboard = $this->dashboardScraper->scrape($email);

        return $this->render('fut/dashboard.html.twig', [
            'dashboard' => $dashboard,
            'accountEmail' => $email,
            'error' => $dashboard->error,
        ]);
    }
}
