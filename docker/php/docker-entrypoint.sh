#!/bin/sh
set -e

# Смонтированный том: владелец uid хоста ≠ www-data в контейнере
git config --global --add safe.directory /var/www/html 2>/dev/null || true
git config --global --add safe.directory '*' 2>/dev/null || true

mkdir -p /tmp/.chromium-0 /tmp/.chromium-33 var/browser-profiles /tmp/panther-error-screenshots
chmod 0777 /tmp/.chromium-0 /tmp/.chromium-33 var/browser-profiles /tmp/panther-error-screenshots 2>/dev/null || true
chown -R www-data:www-data var/browser-profiles /tmp/.chromium-33 2>/dev/null || true

exec docker-php-entrypoint "$@"
