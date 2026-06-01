<?php

namespace App\Interface\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Panther\Client;
use Throwable;

#[AsCommand(
    name: 'app:scrape:ea',
    description: 'Поставить в очередь парсинг каталога игрового сайта',
)]
class TestCommand extends Command
{


    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = null;
        $targetUrl = 'https://www.ea.com/ru/games/ea-sports-fc/fc-25';
        $client = Client::createChromeClient(
        );
dd($this->getRegistrationLink($targetUrl));
        try {
            $id = bin2hex(random_bytes(6));
            $logPath = '/tmp/chromedriver-'.$id.'.log';
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

            $client = Client::createChromeClient(
//                chromeDriverBinary: null,
//                arguments: [
//                    '--headless=new',
//                    '--no-sandbox',
//                    '--disable-dev-shm-usage',
//                    '--disable-gpu',
//                    '--disable-blink-features=AutomationControlled',
//                    '--window-size=1366,768',
//                    '--lang=ru-RU,ru',
//                    '--user-agent='.$userAgent,
////                    '--user-data-dir='.$userDataDir,
////                    '--crash-dumps-dir='.$crashDir,
//                ],
//                options: [
//                    'host' => '127.0.0.1',
////                    'port' => $port,
//                    'chromedriver_arguments' => [
//                        '--verbose',
//                        '--log-path='.$logPath,
//                    ],
//                ],
            );


            $crawler = $client->request('GET', 'https://www.ea.com/ru/games/ea-sports-fc/fc-25/');
          $crawler->filter('');



        dd($client->getCrawler()->html());



        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            dump($error);
        } finally {
            try {
                $client?->quit();
            } catch (Throwable) {
            }
        }
        return 0;
    }
    public function getRegistrationLink(string $url): ?string
    {
        try {
            $client = Client::createChromeClient();
            // 1. Загружаем страницу
            $crawler = $client->request('GET', $url);

            // Ждем загрузки JavaScript
            $client->waitFor('.registration-button');

            echo "Страница загружена\n";

            // 2. Ищем кнопку регистрации (разные варианты)
            $button = $crawler->filter('.registration-button')->first();

            // Если кнопка не найдена, пробуем другие селекторы
            if ($button->count() === 0) {
                $button = $crawler->filter('button:contains("Регистрация")')->first();
            }

            if ($button->count() === 0) {
                throw new \Exception('Кнопка регистрации не найдена');
            }

            // 3. Кликаем по кнопке
            $button->click();

            // Ждем появления новой вкладки или редиректа
            sleep(2);

            // 4. Получаем URL после клика
            $currentUrl = $client->getCurrentURL();

            // Если URL изменился - это редирект
            if ($currentUrl !== $url) {
                echo "Редирект на: $currentUrl\n";
                return $currentUrl;
            }

            // Проверяем наличие ссылки на странице
            $link = $crawler->filter('a.registration-link')->first();
            if ($link->count() > 0) {
                $href = $link->attr('href');
                echo "Найдена ссылка: $href\n";
                return $href;
            }

            // Проверяем открытые окна
            $windowHandles = $client->getWebDriver()->getWindowHandles();
            if (count($windowHandles) > 1) {
                $client->getWebDriver()->switchTo()->window(end($windowHandles));
                $newUrl = $client->getCurrentURL();
                echo "Новая вкладка: $newUrl\n";
                return $newUrl;
            }

        } catch (\Exception $e) {
            echo "Ошибка: " . $e->getMessage() . "\n";
        }

        return null;
    }
}
