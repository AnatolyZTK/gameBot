<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Admin;

use App\Infrastructure\Log\ScrapingLogReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LogsController extends AbstractController
{
    public function __construct(
        private ScrapingLogReader $logReader,
    ) {
    }

    #[Route('/admin/logs', name: 'admin_logs', methods: ['GET'])]
    public function index(): Response
    {
        $snapshot = $this->logReader->readTail(250);

        return $this->render('admin/logs/index.html.twig', [
            'snapshot' => $snapshot,
        ]);
    }

    #[Route('/admin/logs/stream', name: 'admin_logs_stream', methods: ['GET'])]
    public function tail(Request $request): JsonResponse
    {
        $lines = max(1, min(2000, (int) $request->query->get('lines', 200)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $snapshot = $this->logReader->readTail($lines, $offset);

        return $this->json([
            'exists' => $snapshot['exists'],
            'size' => $snapshot['size'],
            'mtime' => $snapshot['mtime'],
            'offset' => $snapshot['offset'],
            'lines' => $snapshot['lines'],
            'path' => 'var/log/scraping.log',
        ]);
    }
}
