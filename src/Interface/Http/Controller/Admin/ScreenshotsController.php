<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ScreenshotsController extends AbstractController
{
    private string $screenshotsDir;

    public function __construct(string $projectDir)
    {
        $this->screenshotsDir = '/tmp/panther-error-screenshots';
    }

    #[Route('/admin/screenshots', name: 'admin_screenshots', methods: ['GET'])]
    public function index(): Response
    {
        $files = [];

        if (is_dir($this->screenshotsDir)) {
            $entries = glob($this->screenshotsDir.'/*.png') ?: [];
            rsort($entries);

            foreach ($entries as $path) {
                $files[] = [
                    'name' => basename($path),
                    'size' => filesize($path),
                    'mtime' => filemtime($path),
                    'path' => $path,
                ];
            }
        }

        return $this->render('admin/screenshots/index.html.twig', [
            'files' => $files,
            'dir' => $this->screenshotsDir,
        ]);
    }

    #[Route('/admin/screenshots/{filename}', name: 'admin_screenshots_view', methods: ['GET'],
        requirements: ['filename' => '[a-zA-Z0-9_\-\.]+\.png'])]
    public function view(string $filename): Response
    {
        $path = $this->screenshotsDir.'/'.$filename;

        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }

        return new BinaryFileResponse($path);
    }

    #[Route('/admin/screenshots/{filename}/delete', name: 'admin_screenshots_delete', methods: ['POST'],
        requirements: ['filename' => '[a-zA-Z0-9_\-\.]+\.png'])]
    public function delete(string $filename): Response
    {
        $path = $this->screenshotsDir.'/'.$filename;

        if (is_file($path)) {
            unlink($path);
        }

        return $this->redirectToRoute('admin_screenshots');
    }

    #[Route('/admin/screenshots/clear', name: 'admin_screenshots_clear', methods: ['POST'])]
    public function clear(): Response
    {
        if (is_dir($this->screenshotsDir)) {
            foreach (glob($this->screenshotsDir.'/*.png') ?: [] as $file) {
                unlink($file);
            }
        }

        $this->addFlash('success', 'Все скриншоты удалены');

        return $this->redirectToRoute('admin_screenshots');
    }
}
