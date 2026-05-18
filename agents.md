Общая идея
Проект построен по мотивам Clean Architecture и Hexagonal (Ports & Adapters): бизнес-логика не зависит от Symfony, MySQL, Manticore или MinIO. Зависимости направлены внутрь — от внешних слоёв к ядру.
┌─────────────────────────────────────────────────────────┐
│  Interface (Delivery)                                   │
│  CLI-команды, HTTP-контроллеры                          │
└──────────────────────────┬──────────────────────────────┘
│
┌──────────────────────────▼──────────────────────────────┐
│  Application                                            │
│  Use cases, сообщения, handlers, расписание             │
└──────────────────────────┬──────────────────────────────┘
│
┌──────────────────────────▼──────────────────────────────┐
│  Domain                                                 │
│  Контракты (порты), value objects                       │
└──────────────────────────▲──────────────────────────────┘
│ реализуют
┌──────────────────────────┴──────────────────────────────┐
│  Infrastructure                                         │
│  HTTP-клиент, парсер, Doctrine, Manticore, S3           │
└─────────────────────────────────────────────────────────┘
Папка Interface/ — это не PHP-интерфейсы, а слой доставки (entry points): как приложение вызывается снаружи.
Слои и ответственность
Domain (src/Domain/)
Самый «чистый» слой — без фреймворка.
Contract/ — порты (абстракции): BrowserClientInterface, PageParserInterface
ValueObject/ScrapedItem — неизменяемый объект результата парсинга (readonly class)
Домен говорит: «мне нужен браузер и парсер», но не знает, как именно они устроены.
Application (src/Application/)
Оркестрация сценариев — что делать, без деталей HTTP/БД.
UseCase/ScrapeCatalogUseCase — сценарий: скачать → распарсить → отправить дальше
Message/ — команды для очереди (ScrapePageMessage, ProcessParsedDataMessage)
Handler/ — обработчики сообщений (#[AsMessageHandler])
Schedule/ — периодический запуск парсинга
Infrastructure (src/Infrastructure/)
Адаптеры к внешнему миру.
Папка	Что адаптирует
Browser/	Игровой сайт (HTTP, cookies, задержки)
Parser/	HTML → ScrapedItem[]
Persistence/	MySQL через Doctrine
Search/Manticore/	Полнотекстовый индекс
Storage/S3/	Файлы в S3/MinIO
Interface (src/Interface/)
Тонкий слой входа: переводит внешний запрос в вызов application-слоя.
ScrapeCatalogCommand — CLI → MessageBus::dispatch(ScrapePageMessage)
HealthController — проверка живости
Паттерны, которые здесь есть
1. Ports & Adapters (Hexagonal)
   Порт — интерфейс в Domain:
   Адаптер — реализация в Infrastructure:
   Привязка в config/services.yaml:
   Можно подменить парсер или HTTP-клиент, не трогая use case.
2. Dependency Inversion (SOLID)
   ScrapeCatalogUseCase зависит от абстракций, а не от HttpBrowserClient:
   ScrapeCatalogUseCase.php
   Lines 15-20
3. Use Case (Application Service)
   ScrapeCatalogUseCase — один сценарий с одной точкой входа execute(). В нём нет SQL, XPath и заголовков HTTP — только координация.
4. Message / Command + Handler (CQRS-подобно)
   Сообщения — простые immutable DTO:
   ScrapePageMessage — «запусти парсинг страницы X»
   ProcessParsedDataMessage — «сохрани и проиндексируй элемент»
   Обработчики подписаны через #[AsMessageHandler] — это Command Handler в терминах CQRS.
   Очередь в Redis (messenger.yaml) отделяет медленный парсинг от быстрого ответа CLI/HTTP.
5. Repository
   GameItemRepository инкапсулирует доступ к БД и метод upsertFromMessage() — логика «найти по external_id или создать» не размазана по handlers.
6. Value Object
   ScrapedItem — readonly, без идентичности в БД: просто набор полей результата парсинга. Не Entity, потому что ещё не сохранён.
7. Factory
   ManticoreClientFactory создаёт Manticoresearch\Client с host/port из env. В DI зарегистрирован как factory-сервис — удобно для тестов и смены конфигурации.
8. Adapter / Gateway
   HttpBrowserClient — gateway к игровому сайту (User-Agent, cookies, retry, rate limit)
   GameItemIndexer — gateway к Manticore
   ScreenshotStorage — gateway к S3 через Flysystem
9. Strategy (через DI)
   PageParserInterface с реализацией GameSitePageParser — по сути Strategy: другой парсер = другой класс + смена биндинга в DI, без правок use case.
10. Разделение Write / Read (упрощённый CQRS)
    Операция	Хранилище
    Запись, upsert, связи	MySQL (source of truth)
    Поиск по тексту	Manticore (read model / индекс)
    ProcessParsedDataMessageHandler пишет в оба: сначала MySQL, потом индекс — классический проекционный подход, только без отдельного event store.
11. Scheduler + Async
    ScrapingScheduleProvider — cron/interval → сообщение в очередь async. Парсинг не блокирует планировщик.
12. Upsert
    В репозитории: есть external_id — обновить, нет — создать. Идемпотентность при повторном парсинге.
    Поток данных (end-to-end)
    Два этапа в очереди — намеренно:
    Скачивание + парсинг — тяжёлое, с задержками, retry
    Сохранение + индексация — можно масштабировать отдельными воркерами
    Что даёт такая архитектура для вашего кейса
    Парсер с имитацией пользователя — вся «грязь» (headers, cookies, sleep, 429) сидит в HttpBrowserClient. Use case остаётся читаемым.
    Смена сайта — новый GameSitePageParser (или несколько парсеров под разные разделы).
    Тесты — mock BrowserClientInterface с фикстурой HTML, без реальных запросов.
    Масштаб — больше worker-контейнеров, парсинг и сохранение параллельно.
    Чего пока нет (и это нормально для старта)
    Паттерн	Статус
    Domain Events	Не используются — вместо них Messenger-сообщения
    Aggregate / Rich Domain Model	Entity в Infrastructure, логика частично в Repository
    Отдельный Read Model sync	Индекс обновляется синхронно в handler
    Specification, Unit of Work	Не выделены явно
    Anti-Corruption Layer для всего сайта	Только парсер + browser client
    Это прагматичный вариант Clean Architecture под Symfony: не DDD «в лоб», а слои + порты + очереди.
    Symfony-специфика (не паттерны GoF, но важно)
    DI Container — autowire, явные биндинги интерфейсов
    Messenger — шина команд между слоями и воркерами
    Doctrine — ORM в Infrastructure (entity не в Domain — компромисс)
    readonly class — иммутабельность DTO и use cases в PHP 8.4
