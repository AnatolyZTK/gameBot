#!/bin/sh
set -e

# Смонтированный том: владелец uid хоста ≠ www-data в контейнере
git config --global --add safe.directory /var/www/html 2>/dev/null || true
git config --global --add safe.directory '*' 2>/dev/null || true

mkdir -p /tmp/.chromium-0 /tmp/.chromium-33 var/browser-profiles /tmp/panther-error-screenshots var/cache var/log
chmod 0777 /tmp/.chromium-0 /tmp/.chromium-33 var/browser-profiles /tmp/panther-error-screenshots 2>/dev/null || true
chmod -R 777 var/cache var/log 2>/dev/null || true
chown -R www-data:www-data var/browser-profiles /tmp/.chromium-33 2>/dev/null || true

# Dev: том ./ смонтирован с хоста — vendor часто отсутствует до первого composer install
# На сервере init-сервис уже выполнил composer install — пропускаем
if [ "${SKIP_COMPOSER:-0}" != "1" ] && [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
    echo "[entrypoint] vendor/ not found — running composer install..."
    composer install --prefer-dist --no-interaction --no-scripts ${COMPOSER_FLAGS:-}
fi

exec docker-php-entrypoint "$@"
