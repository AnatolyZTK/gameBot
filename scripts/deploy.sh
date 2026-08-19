#!/usr/bin/env bash
# Развёртывание на удалённом сервере (Ubuntu/Debian + Docker Compose v2).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

COMPOSE_FILES="${COMPOSE_FILE:-docker-compose.yml:docker-compose.prod.yml}"
export COMPOSE_FILE="$COMPOSE_FILES"

log() { printf '==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null || die "Docker не установлен. Установите: https://docs.docker.com/engine/install/"
docker compose version >/dev/null 2>&1 || die "Нужен Docker Compose v2 (docker compose, не docker-compose)"

gen_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 16
    else
        head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

set_env_var() {
    local key="$1"
    local value="$2"
    local file="$3"
    if grep -q "^${key}=" "$file"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >>"$file"
    fi
}

ensure_env_var() {
    local key="$1"
    local file="$2"
    local current
    current="$(grep "^${key}=" "$file" 2>/dev/null | cut -d= -f2- || true)"
    if [ -z "$current" ]; then
        set_env_var "$key" "$(gen_secret)" "$file"
        log "Сгенерирован ${key}"
    fi
}

if [ ! -f .env ]; then
    cp .env.dist .env
    log "Создан .env из .env.dist"
fi

# shellcheck disable=SC1091
set -a
source .env 2>/dev/null || true
set +a

ensure_env_var APP_SECRET .env
ensure_env_var MYSQL_ROOT_PASSWORD .env
ensure_env_var MYSQL_PASSWORD .env
ensure_env_var MINIO_ROOT_USER .env
ensure_env_var MINIO_ROOT_PASSWORD .env

if [ "${APP_ENV:-dev}" = "prod" ] && grep -q '^APP_SECRET=change_me' .env 2>/dev/null; then
    set_env_var APP_SECRET "$(gen_secret)" .env
fi

# Перечитать после генерации, синхронизировать DATABASE_URL
set -a
source .env 2>/dev/null || true
set +a

DB_USER="${MYSQL_USER:-app}"
DB_PASS="${MYSQL_PASSWORD}"
DB_NAME="${MYSQL_DATABASE:-app}"
COMPUTED_URL="mysql://${DB_USER}:${DB_PASS}@mysql:3306/${DB_NAME}?serverVersion=8.0.32&charset=utf8mb4"
set_env_var DATABASE_URL "${COMPUTED_URL}" .env
log "DATABASE_URL синхронизирован с MYSQL_USER/MYSQL_PASSWORD"

if [ ! -f docker/nginx/.htpasswd ]; then
    ADMIN_USER="${NGINX_AUTH_USER:-admin}"
    ADMIN_PASS="${NGINX_AUTH_PASSWORD:-}"
    if [ -z "$ADMIN_PASS" ]; then
        ADMIN_PASS="$(gen_secret | cut -c1-16)"
        log "Сгенерирован пароль для HTTP Basic Auth: пользователь=${ADMIN_USER}, пароль=${ADMIN_PASS}"
        log "Сохраните пароль! Он больше не будет показан."
    fi
    printf '%s:%s\n' "$ADMIN_USER" "$(openssl passwd -apr1 "$ADMIN_PASS")" > docker/nginx/.htpasswd
    log "Создан docker/nginx/.htpasswd"
fi

log "Сборка образов (Chrome может качаться 5–10 минут)..."
docker compose build php worker init

log "Запуск инфраструктуры..."
docker compose up -d mysql redis minio manticore
docker compose up minio-init

log "Ожидание MySQL..."
for i in $(seq 1 60); do
    if docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 -u"${MYSQL_USER:-app}" -p"${MYSQL_PASSWORD:-app}" --silent 2>/dev/null; then
        break
    fi
    if [ "$i" -eq 60 ]; then
        die "MySQL не поднялся за 60 секунд. Проверьте: docker compose logs mysql"
    fi
    sleep 2
done

log "Composer + миграции (init)..."
docker compose run --rm init

log "Запуск приложения..."
docker compose up -d

log "Статус контейнеров:"
docker compose ps

HOST="${DEPLOY_HOST:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
PORT="${NGINX_HTTP_PORT:-80}"
echo ""
echo "Готово."
echo "  Админка:  http://${HOST}:${PORT}/admin"
echo "  Логи:     docker compose logs -f worker"
echo "  Обновление после git pull: docker compose run --rm init && docker compose up -d --build"
