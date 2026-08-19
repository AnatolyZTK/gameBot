# Gameparser / FUT Coin Transfer

Symfony-приложение для парсинга и автоматизации переводов монет EA FUT.

## Локальная разработка

```bash
cp .env.dist .env
# Для локалки: APP_ENV=dev, NGINX_HTTP_PORT=8080 в .env

COMPOSE_FILE=docker-compose.yml make install
# или по шагам:
docker compose up -d
docker compose run --rm init
docker compose up -d
```

Админка: http://localhost:8080/admin

## Развёртывание на удалённом сервере

### Требования

- Ubuntu/Debian (или другой Linux) с Docker Engine и **Docker Compose v2**
- Минимум **4 GB RAM**, **2 CPU** (Chrome + MySQL + worker)
- Открытый порт **80** (или свой `NGINX_HTTP_PORT`) для nginx
- Git: `git clone …` в каталог на сервере

### Быстрый старт

```bash
git clone <repo-url> gameparser
cd gameparser

cp .env.dist .env
# При необходимости отредактируйте .env (EA-аккаунты, Futbin cookies)

chmod +x scripts/deploy.sh
./scripts/deploy.sh
```

Скрипт:

1. Создаёт `.env` и генерирует пароли (`APP_SECRET`, MySQL, MinIO), если они пустые
2. Собирает образы PHP/worker (Chrome ~5–10 мин)
3. Поднимает MySQL, Redis, MinIO, Manticore
4. Запускает `composer install` и миграции
5. Стартует nginx, php-fpm и worker

Админка: `http://<IP-сервера>/admin`

### Обновление после `git pull`

```bash
docker compose run --rm init
docker compose up -d --build
docker compose restart worker
```

### Старые CPU (без x86-64-v2)

Образы MySQL 8.0.32 и MinIO уже выбраны для baseline x86-64. Если раньше использовались другие версии и MySQL/MinIO не стартуют:

```bash
docker compose down
docker volume rm gameparser_mysql_data gameparser_minio_data
./scripts/deploy.sh
```

### Полезные команды

```bash
make help              # все цели Makefile
docker compose ps      # статус контейнеров
docker compose logs -f worker
make migrate           # только миграции
make account-login     # логин EA-аккаунтов (xvfb)
make transfer-plan     # план перевода (dry-run)
```

### Безопасность на сервере

- MySQL, Redis, MinIO и Manticore в prod-слое слушают только `127.0.0.1` (не торчат наружу)
- Смените пароли в `.env` до первого деплоя в production
- Настройте firewall: открыт только HTTP/HTTPS (и SSH)

### Redis warning `vm.overcommit_memory`

Предупреждение безвредно. Чтобы убрать:

```bash
sudo sysctl vm.overcommit_memory=1
echo 'vm.overcommit_memory = 1' | sudo tee -a /etc/sysctl.conf
```

### Порт 9000 занят (MinIO)

В prod MinIO **не пробрасывается на хост** — приложение ходит на `http://minio:9000` внутри Docker. Обновите код и перезапустите:

```bash
git pull
docker compose down
docker compose up -d
```

Если ошибка остаётся на старой версии compose-файлов, временно смените порты в `.env`:

```env
MINIO_API_PORT=9010
MINIO_CONSOLE_PORT=9011
```

Узнать, кто занял 9000:

```bash
sudo ss -tlnp | grep ':9000'
```
