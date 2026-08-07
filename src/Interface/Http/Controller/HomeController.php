<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('admin_dashboard');
    }
}
