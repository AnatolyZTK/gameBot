#!/bin/sh
set -e

# Смонтированный том: владелец uid хоста ≠ www-data в контейнере
git config --global --add safe.directory /var/www/html 2>/dev/null || true
git config --global --add safe.directory '*' 2>/dev/null || true

exec docker-php-entrypoint "$@"
