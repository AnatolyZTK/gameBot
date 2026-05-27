<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller;

use Throwable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Panther\Client;
use Symfony\Component\Routing\Attribute\Route;

final class WindowController extends AbstractController
{
    #[Route('/window', name: 'window', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $client = null;
        $error = null;
        $debug = (bool) $request->query->get('debug', false);

        try {
            $id = bin2hex(random_bytes(6));

            // Уникальные директории для каждого запроса, чтобы не ловить гонки/конфликты.
            $userDataDir = '/tmp/panther-user-'.$id;
            $crashDir = '/tmp/panther-crashes-'.$id;
            @mkdir($userDataDir, 0700, true);
            @mkdir($crashDir, 0700, true);

            // Уникальный порт для chromedriver.
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if (!is_resource($server)) {
                throw new \RuntimeException(sprintf('Unable to allocate port: %s', $errstr ?: $errno));
            }
            $name = stream_socket_get_name($server, false);
            fclose($server);
            $port = (int) substr(strrchr((string) $name, ':'), 1);

            $logPath = '/tmp/chromedriver-'.$id.'.log';

            // ВАЖНО: второй аргумент — это chrome arguments, а первый аргумент — путь к chromedriver.
            $client = Client::createChromeClient(
                chromeDriverBinary: null,
                arguments: [
                '--headless=new',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--user-data-dir='.$userDataDir,
                '--crash-dumps-dir='.$crashDir,
                ],
                options: [
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'chromedriver_arguments' => [
                        '--verbose',
                        '--log-path='.$logPath,
                    ],
                ],
            );
            dd($client->request('GET', 'https://example.com')->html());

        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        } finally {
            try {
                $client?->quit();
            } catch (Throwable) {
                // ignore
            }
        }

        return $this->render('window.html.twig', [
            'title' => 'Window',
            'error' => $debug ? $error : null,
        ]);
    }
}
