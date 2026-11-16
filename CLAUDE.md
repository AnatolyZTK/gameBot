# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Что это

`gameparser` — Symfony 7.4 / PHP 8.4 приложение для автоматизации переводов монет в EA FUT
(Ultimate Team) через управляемый Chrome, плюс парсинг цен с Futbin и обобщённый парсер каталога.
Управление — CLI-команды (`app:*`) и Twig-админка на `/admin`. Всё крутится в Docker.

Документация в репозитории: `README.md` (деплой, траблшутинг), `agents.md` (описание слоёв и
паттернов; покрывает в основном scraping-слайс, transfer-слайс появился позже).

## Команды

Локального PHP нет — всё через Docker Compose. `Makefile` — тонкая обёртка (`make help`).

```bash
# Первый запуск локально (важно: COMPOSE_FILE, иначе подхватится prod-overlay из .env)
cp .env.dist .env                                # APP_ENV=dev, NGINX_HTTP_PORT=8080
COMPOSE_FILE=docker-compose.yml make install     # build + инфра + init + up
# админка: http://localhost:8080/admin

make migrate            # = docker compose run --rm init (composer install + миграции + cache:clear)
make shell              # bash в php-контейнере
make logs               # docker compose logs -f
docker compose logs -f worker
```

Любая консольная команда: `docker compose exec php php bin/console <cmd>`.

Тесты (PHPUnit 11 через `bin/phpunit`):

```bash
docker compose exec php php bin/phpunit                                  # всё
docker compose exec php php bin/phpunit tests/Unit                       # только юнит-тесты
docker compose exec php php bin/phpunit --filter testGrossUpForTax       # один тест
docker compose exec php php bin/phpunit tests/Unit/.../MathServiceTest.php   # один файл
```

Инфраструктура (MySQL, Redis, Chrome) для тестов не нужна — достаточно контейнера `php`.

Структура:

- `tests/Unit/` — зеркалит `src/`: чистая логика (расчёт цен, энтити, парсеры, чтение логов,
  файловые хранилища), внешние зависимости замоканы.
- `tests/Integration/` — сценарии с настоящими Doctrine-репозиториями (они `final`, замокать нельзя).
  Наследуют `tests/Support/DoctrineTestCase`: SQLite in-memory, схема из атрибутов энтити,
  Kernel не поднимается.
- `tests/Support/` — общие помощники: `DoctrineTestCase`, `CollectingMessageBus` (вместо мока
  `MessageBusInterface`), `TemporaryDirectory`, `StringUuidType`.
- `tests/fixtures/` — фикстуры ответов (например `futbin/player-231747.json`).

Особенности харнесса:

- `StringUuidType` подменяет `uuid` на `CHAR(36)`: в бою UUID лежат в `BINARY(16)`, а SQLite
  сравнивает блобы и текст по-другому, из-за чего `WHERE sender_account_id = :account` не находит
  строк. Без подмены тесты репозиториев дают ложно-зелёный результат.
- Схема строится из атрибутов, а не из `migrations/` (те MySQL-специфичны) — расхождение
  маппинга и миграций тесты не поймают.
- Браузерные сервисы (`FutTransferMarketBrowser`, `EaFutLoginService`, `SniperService`,
  `CrossAccountListAndSnipeService`, `ExecuteTransferHandler`) не покрыты: они `final` и тянут
  `FutWebAppSessionFactory` (тоже `final`), поэтому без выделения интерфейсов замокать их нельзя.
  Проверяются вручную через `app:transfer:pair-test` и `app:fut:market-debug`.
- Два теста фиксируют известные дефекты, а не желаемое поведение (см. их docblock'и):
  коллизию синтетического `futbinId` в `PlayerRepositoryResolver` и падение
  `ScrapingScheduleProvider` без пакета `dragonmantank/cron-expression`.

`phpunit.xml.dist` содержит устаревший `DATABASE_URL` на postgres (артефакт рецепта Flex);
текущие тесты его не используют, но тесту, поднимающему Kernel, DSN придётся задать явно.

Линтеров/статического анализа в проекте нет (нет php-cs-fixer, phpstan, psalm).

### Команды, которым нужен настоящий Chrome

EA FUT Web App блокирует headless и `navigator.webdriver`, поэтому браузерные сценарии
запускаются **headed под Xvfb**:

```bash
docker compose exec -e PANTHER_NO_HEADLESS=1 php xvfb-run -a php bin/console app:account:login --all
```

Так работают make-цели `account-login`, `transfer-pair-test`, `futbin-auth`, `scrape-ea`.
Остальные (`transfer-plan`, `transfer-run`, `transfer-list`, `sync-prices`, `fut-market-test`,
`account-add/list/seed`) — обычный `exec php`.

Полный список: `app:account:add|list|seed|login`, `app:transfer:plan|run|list|show|pair-test`,
`app:fut:market-test|market-debug`, `app:sync:prices`, `app:futbin:auth`, `app:ea:totp`,
`app:scrape:catalog`, `app:scrape:ea` (последняя лежит в `TestCommand.php`).

## Архитектура

### Слои

Clean Architecture / Ports & Adapters, зависимости направлены внутрь:

- `src/Domain/` — контракты-порты (`BrowserClientInterface`, `PageParserInterface`), enum'ы
  (`Platform`, `TransferStatus`, `LoginStatus`), readonly value objects. Без фреймворка.
- `src/Application/` — сценарии: use cases, Messenger-сообщения, `#[AsMessageHandler]`-обработчики,
  `#[AsSchedule]`-провайдеры, сервисы-оркестраторы.
- `src/Infrastructure/` — адаптеры: Chrome/Panther, Futbin, Doctrine (`Persistence/`), Manticore
  (`Search/`), S3 (`Storage/`), парсеры HTML/JSON.
- `src/Interface/` — слой доставки: `Command/` (CLI) и `Http/Controller/` (в т.ч. `Admin/`).

Два вертикальных слайса, оба присутствуют в Application и Infrastructure: **Scraping** и **Transfer**.

Важные особенности разбиения:

- Doctrine-энтити живут в `src/Infrastructure/Persistence/Entity` (осознанный компромисс, mapping в
  `config/packages/doctrine.yaml` указывает именно туда). Репозитории — рядом, в `Persistence/Repository`.
- `src/Entity/`, `src/Repository/`, `src/Controller/` — пустые остатки рецептов Flex (только
  `.gitignore`). Новый код туда не кладём; MakerBundle по умолчанию генерирует именно туда — правьте путь.
- Порты биндятся вручную в `config/services.yaml` (`BrowserClientInterface` → `HttpBrowserClient`,
  `PageParserInterface` → `GameSitePageParser`), там же скалярные аргументы и `env(...)`-дефолты
  всех `app.*`-параметров. Новую настройку добавляйте туда, а не только в `.env`.
- Роутинг — только атрибуты, ресурс `src/Interface/Http/Controller/` (`config/routes.yaml`).

### Асинхронность

Всё тяжёлое уходит в Messenger-транспорт `async` (Redis, см. `config/packages/messenger.yaml`),
retry 3 раза, failure transport `failed`. Воркер — сервис `worker`: `docker/php/worker.sh` крутит
`messenger:consume async --time-limit=3600` в бесконечном цикле (без цикла контейнер умирал через час).

Планировщик (`scheduler`) вынесен в compose-профиль `scheduler` и по умолчанию **не поднимается**;
`make scheduler` его включает. `ScrapingScheduleProvider` задаёт: каталог каждые 15 минут, полный
парсинг по крону `0 3 * * *`, `app:sync:prices --favorites-only` каждые 10 минут.

### Transfer: как переводятся монеты

Перевод — это «продай карточку дорого тому, кому нужны монеты»:

1. `TransferOrchestrator::planTransfer()` — `MathService::calculateRequiredAmounts()` бьёт сумму на
   шаги ≤ 500k и делает gross-up под 5% налог EA (`grossUpForTax`), округляя до валидной BIN-цены
   (`binStep`/`roundUpToValidBin` — лестница шагов цены на рынке EA).
2. `createAndQueueTransfer()` сохраняет `Transfer` с планом в JSON-колонке и кидает
   `ExecuteTransferMessage` в очередь.
3. `ExecuteTransferHandler` перепроверяет safety, ставит статус `executing` и для каждого шага
   вызывает `CrossAccountListAndSnipeService::listAndSnipe()`: **lister** покупает дешёвую случайную
   карточку, выставляет её по grossed-up цене, **sniper** мгновенно её выкупает. Монеты получает тот,
   кто листит, поэтому при переводе «на receiver» листит receiver, а снайпит sender
   (поля `lister`/`sniper` в шаге плана).
4. По завершении — `SafetyService::applyCooldown()` + `Account::registerSale()` на аккаунт-lister.

`buildPlayerPlan()` сейчас генерирует только шаги `mode: 'any'` (покупка любой карточки
до ~8% от цены листинга, коридор 2k–12k): named-flip по конкретному игроку признан хрупким.
Ветка `mode: 'named'` в `ExecuteTransferHandler` (через `SniperService` и `PlayerRepository`)
рабочая, но из планировщика недостижима.

**Safety-лимиты** (`SafetyService` + `Account`): `Account::DAILY_SALES_LIMIT = 4` продажи в сутки,
24-часовой cooldown после перевода, и одна пара sender↔receiver — не чаще раза в 24 часа
(`TransferRepository::countPairTransfersSince`).

### Браузерный стек (самое хрупкое место)

- `StealthChromeClientFactory` — создаёт Panther-клиент: инъекция stealth-JS (гасит
  `navigator.webdriver`, подделывает plugins/языки), WebGL через SwiftShader (EA `unsupported.js`
  требует WebGL, GPU в Docker нет), `--headless=new` только если не выставлен `PANTHER_NO_HEADLESS`,
  случайный свободный порт под chromedriver, `HOME`/`XDG_*` в `/tmp`. Здесь же — распознавание
  экранов EA (`isFutAppAuthenticated`, `waitForStableSession`, `dismissBlockingOverlays`).
- `FutWebAppSession::open()` — единственный вход в Web App: открывает страницу, ждёт стабильную
  сессию, несколько раз закрывает промо-модалки. Если сессии нет — бросает исключение с подсказкой
  запустить `app:account:login`. Логиниться внутри сценария он не пытается.
- `FutWebAppSessionFactory::withAccount()` — обёртка «открыть → выполнить → гарантированно закрыть»,
  попутно обновляет `login_status`/`last_login_error` у аккаунта. Все браузерные сервисы
  (`SniperService`, `CrossAccountListAndSnipeService`) ходят только через неё.
- `BrowserProfileSnapshotStorage` — на каждый аккаунт свой Chrome `user-data-dir` в
  `var/browser-profiles/<key>`; именно так живут cookies и сессия между запусками. Правит права,
  т.к. профиль пишет root-воркер, а читает www-data.
- Прокси: `Account::proxyUrl` или `EA_HTTPS_PROXY`/`HTTPS_PROXY`. Chrome не умеет прокси с Basic
  Auth, поэтому `LocalHttpProxyRelay` поднимает PHP-подпроцесс
  (`Infrastructure/Browser/Scripts/local-http-proxy-relay.php`) — локальный прокси без авторизации,
  проксирующий в upstream с креденшелами. Не забывайте `stopProxyRelay()` (делается в `close()`).
- 2FA: `TotpCodeProvider` (spomky-labs/otphp) генерирует код из `Account::totpSecret` локально;
  `EaFutLoginService` прогоняет форму входа EA по CSS-селекторам.
- `FutTransferMarketBrowser` (~1.8k строк) — вся работа с UI рынка: поиск, покупка, листинг, снайп,
  чтение баланса. Селекторы EA ломаются при обновлениях Web App; отладка — `app:fut:market-debug`
  и скриншоты.

### Данные

MySQL 8.0.32 — source of truth (`accounts`, `players`, `transfers`, `game_items`; UUID-ключи через
symfony/uid). Manticore — read-model для полнотекстового поиска: `ProcessParsedDataMessageHandler`
пишет сначала в MySQL (`upsertFromMessage`, идемпотентный upsert по `external_id`), потом в индекс.
MinIO/S3 через Flysystem — скриншоты (`ScreenshotStorage`).

Цены Futbin: `app:sync:prices` → `PriceParserService` → `FutbinHttpClient` (JSON-API) или
`FutbinBrowserFetcher` (Chrome, когда нужен обход защиты) → `Player`. Требуются cookies, их
сохраняет `app:futbin:auth` в `var/futbin/cookies.json`.

Scraping-слайс общего каталога (`ScrapeCatalogUseCase`, `GameSitePageParser`, `GameItem`) —
рабочий каркас, но `GAME_SITE_BASE_URL` по умолчанию пуст.

## Docker и окружение

- Образ `docker/php/Dockerfile` имеет таргеты `dev` и `prod` (`DOCKER_PHP_TARGET`), внутри — Chrome,
  chromedriver, Xvfb. Сборка ~5–10 минут.
- Сервисы: `nginx`, `php` (fpm), `worker`, `init` (one-shot), `scheduler` (профиль), `mysql`,
  `redis`, `manticore`, `minio` + `minio-init`. Сервис `database` (postgres) — неиспользуемый
  остаток рецепта, профиль `postgres`.
- `docker-compose.prod.yml` — overlay: убирает публикацию портов инфраструктуры, `APP_ENV=prod`,
  `SKIP_COMPOSER=1`, basic-auth для nginx (`docker/nginx/.htpasswd`, в git не хранится).
  `.env.dist` по умолчанию задаёт `COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml`, поэтому
  для локальной работы переопределяйте `COMPOSE_FILE`.
- `var/` — **отдельный Docker volume `app_var`**, не смонтированная папка хоста. Логи, скриншоты и
  профили браузера на хосте не появятся: `docker compose exec php tail -f var/log/scraping.log`.
  `docker-entrypoint.sh` при каждом старте выравнивает права (`chmod a+rwX var`) и поднимает Xvfb,
  если `DISPLAY` пуст.
- Версии MySQL/MinIO зафиксированы под baseline x86-64 (без x86-64-v2) — не обновляйте их бездумно,
  на целевых серверах свежие образы не стартуют.
- `.env` в репозитории есть и отслеживается git'ом, но содержит только плейсхолдеры; реальные
  секреты — в `.env` на сервере (генерирует `scripts/deploy.sh`) или в `.env.local`.

## Соглашения в коде

- `declare(strict_types=1)`, `final class`, promoted constructor properties, `readonly` для DTO и
  use cases, PHP 8.4.
- Комментарии, тексты исключений и сообщения CLI — по-русски; идентификаторы — по-английски.
- Логирование браузерных/парсинг-сценариев — в отдельный monolog-канал `scraping`
  (`#[Autowire(service: 'monolog.logger.scraping')]`), файл `var/log/scraping.log`, просмотр в
  админке на `/admin/logs`. Скриншоты ошибок Panther — `var/screenshots`
  (`PANTHER_ERROR_SCREENSHOT_DIR`), просмотр на `/admin/screenshots`.
- Аккаунт в CLI можно указывать и по email, и по UUID — `AccountIdentifierResolver`.
