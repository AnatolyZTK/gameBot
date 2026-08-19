#!/bin/sh
set -e

# Смонтированный том: владелец uid хоста ≠ www-data в контейнере
git config --global --add safe.directory /var/www/html 2>/dev/null || true
git config --global --add safe.directory '*' 2>/dev/null || true

mkdir -p /tmp/.chromium-0 /tmp/.chromium-33 var/browser-profiles /tmp/panther-error-screenshots \
    var/cache/prod/http_cache var/cache/dev var/log var/sessions
chmod 0777 /tmp/.chromium-0 /tmp/.chromium-33 var/browser-profiles /tmp/panther-error-screenshots 2>/dev/null || true
chmod -R 777 var/cache var/log var/sessions 2>/dev/null || true
chown -R www-data:www-data var/browser-profiles /tmp/.chromium-33 2>/dev/null || true

# Dev: том ./ смонтирован с хоста — vendor часто отсутствует до первого composer install
# На сервере init-сервис уже выполнил composer install — пропускаем
if [ "${SKIP_COMPOSER:-0}" != "1" ] && [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
    echo "[entrypoint] vendor/ not found — running composer install..."
    composer install --prefer-dist --no-interaction --no-scripts ${COMPOSER_FLAGS:-}
fi

# Запустить Xvfb если нет дисплея (нужен для Chrome в worker)
if [ -z "${DISPLAY:-}" ] && command -v Xvfb >/dev/null 2>&1; then
    XVFB_DISPLAY="${XVFB_DISPLAY:-:99}"
    Xvfb "$XVFB_DISPLAY" -screen 0 1280x1024x24 -nolisten tcp &
    export DISPLAY="$XVFB_DISPLAY"
    sleep 1
fi

exec docker-php-entrypoint "$@"
