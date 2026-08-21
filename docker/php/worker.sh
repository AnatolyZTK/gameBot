#!/bin/sh
# Messenger worker: перезапускает consume после time-limit / падения.
set -eu

while true; do
    echo "[worker] starting messenger:consume..."
    php bin/console messenger:consume async -vv \
        --time-limit=3600 \
        --memory-limit=512M \
        || echo "[worker] consume exited with code $?, restarting in 3s..."
    sleep 3
done
