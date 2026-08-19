#!/bin/sh
set -e

echo "[init] Installing PHP dependencies..."
composer install --prefer-dist --no-interaction ${COMPOSER_FLAGS:-}

echo "[init] Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "[init] Clearing cache..."
php bin/console cache:clear --no-warmup 2>/dev/null || true

echo "[init] Ready."
