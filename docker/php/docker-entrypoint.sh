#!/bin/sh
set -e

# Смонтированный том: владелец uid хоста ≠ www-data в контейнере
git config --global --add safe.directory /var/www/html 2>/dev/null || true
git config --global --add safe.directory '*' 2>/dev/null || true

# var/ — Docker volume (app_var). Всегда выравниваем права под php-fpm (www-data).
mkdir -p \
    var/cache/prod/http_cache \
    var/cache/dev \
    var/log \
    var/sessions \
    var/browser-profiles \
    var/screenshots \
    /tmp/.chromium-0 \
    /tmp/.chromium-33

# a+rwX: и root (worker/chrome), и www-data (php-fpm) могут писать
chmod -R a+rwX var /tmp/.chromium-0 /tmp/.chromium-33 2>/dev/null || true
# Скриншоты часто создаёт root (worker) — php-fpm (www-data) должен читать
chmod a+rX var/screenshots 2>/dev/null || true
chmod a+r var/screenshots/*.png 2>/dev/null || true
if command -v chown >/dev/null 2>&1; then
    chown -R www-data:www-data var 2>/dev/null || true
    # После chown снова открыть запись для root-worker/chrome
    chmod -R a+rwX var 2>/dev/null || true
fi

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
