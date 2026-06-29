#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"

cd "$APP_DIR"

if [[ ! -f .env ]]; then
    echo ".env tidak ditemukan di $APP_DIR."
    exit 1
fi

env_value() {
    local key="$1"
    awk -F= -v key="$key" '$1 == key { sub(/^[^=]*=/, ""); gsub(/^"|"$/, ""); print; exit }' .env
}

FCM_CREDENTIALS="$(env_value FIREBASE_CREDENTIALS)"
REVERB_HOST="$(env_value REVERB_HOST)"
REVERB_PORT="$(env_value REVERB_PORT)"
REVERB_SCHEME="$(env_value REVERB_SCHEME)"
BROADCAST_CONNECTION="$(env_value BROADCAST_CONNECTION)"

echo "Docker services:"
docker compose ps

echo
echo "Runtime env:"
echo "BROADCAST_CONNECTION=${BROADCAST_CONNECTION:-<empty>}"
echo "REVERB_HOST=${REVERB_HOST:-<empty>}"
echo "REVERB_PORT=${REVERB_PORT:-<empty>}"
echo "REVERB_SCHEME=${REVERB_SCHEME:-<empty>}"
echo "FIREBASE_CREDENTIALS=${FCM_CREDENTIALS:-<empty>}"

if [[ -z "$FCM_CREDENTIALS" ]]; then
    echo "FCM_NOT_CONFIGURED"
else
    if docker compose exec -T app test -f "$FCM_CREDENTIALS"; then
        echo "FCM_FILE_OK"
    else
        echo "FCM_FILE_MISSING"
        exit 1
    fi
fi

echo
echo "Reverb port listener:"
if ss -ltn | grep -q ":${REVERB_PORT:-8080}"; then
    ss -ltn | grep ":${REVERB_PORT:-8080}"
else
    echo "REVERB_PORT_NOT_LISTENING"
    exit 1
fi

echo
echo "Laravel broadcast config:"
docker compose exec -T app php artisan tinker --execute='dump(["broadcast" => config("broadcasting.default"), "reverb_host" => config("broadcasting.connections.reverb.options.host"), "reverb_port" => config("broadcasting.connections.reverb.options.port"), "reverb_scheme" => config("broadcasting.connections.reverb.options.scheme")]);'

echo
echo "Log terakhir Reverb:"
docker compose logs --tail=40 reverb

echo
echo "Jika test laptop ke port 8080 sudah sukses, jalankan Flutter production dengan REALTIME_DIAGNOSTICS=true untuk memastikan ada connection established dan subscription succeeded."
