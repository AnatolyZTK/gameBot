<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ScreenshotsController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    private function screenshotsDir(): string
    {
        $fromEnv = $_ENV['PANTHER_ERROR_SCREENSHOT_DIR']
            ?? $_SERVER['PANTHER_ERROR_SCREENSHOT_DIR']
            ?? null;

        if (is_string($fromEnv) && $fromEnv !== '') {
            if (!str_starts_with($fromEnv, '/')) {
                return $this->projectDir.'/'.ltrim($fromEnv, './');
            }

            return $fromEnv;
        }

        return $this->projectDir.'/var/screenshots';
    }

    #[Route('/admin/screenshots', name: 'admin_screenshots', methods: ['GET'])]
    public function index(): Response
    {
        $dir = $this->screenshotsDir();
        @mkdir($dir, 0777, true);
        @chmod($dir, 0777);

        $files = [];
        if (is_dir($dir)) {
            $entries = glob($dir.'/*.png') ?: [];
            usort($entries, static fn (string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));

            foreach ($entries as $path) {
                @chmod($path, 0666);
                $files[] = [
                    'name' => basename($path),
                    'size' => @filesize($path) ?: 0,
                    'mtime' => @filemtime($path) ?: 0,
                ];
            }
        }

        return $this->render('admin/screenshots/index.html.twig', [
            'files' => $files,
            'dir' => $dir,
        ]);
    }

    #[Route('/admin/screenshots/clear', name: 'admin_screenshots_clear', methods: ['POST'])]
    public function clear(): Response
    {
        $dir = $this->screenshotsDir();
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.png') ?: [] as $file) {
                @unlink($file);
            }
        }

        $this->addFlash('success', 'Все скриншоты удалены');

        return $this->redirectToRoute('admin_screenshots');
    }

    #[Route('/admin/screenshots/{filename}', name: 'admin_screenshots_view', methods: ['GET'],
        requirements: ['filename' => '[a-zA-Z0-9_\-\.]+\.png'])]
    public function view(string $filename): Response
    {
        $path = $this->screenshotsDir().'/'.$filename;

        if (!is_file($path) || !is_readable($path)) {
            @chmod($path, 0666);
        }

        if (!is_file($path) || !is_readable($path)) {
            throw $this->createNotFoundException('Screenshot not readable: '.$filename);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'image/png');
        $response->setContentDisposition('inline', $filename);

        return $response;
    }

    #[Route('/admin/screenshots/{filename}/delete', name: 'admin_screenshots_delete', methods: ['POST'],
        requirements: ['filename' => '[a-zA-Z0-9_\-\.]+\.png'])]
    public function delete(string $filename): Response
    {
        $path = $this->screenshotsDir().'/'.$filename;
        @chmod($path, 0666);

        if (is_file($path)) {
            @unlink($path);
        }

        return $this->redirectToRoute('admin_screenshots');
    }
}
