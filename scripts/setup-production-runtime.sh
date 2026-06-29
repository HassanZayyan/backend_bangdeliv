#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
OVERRIDE_FILE="${OVERRIDE_FILE:-docker-compose.override.yml}"
SECRET_DIR="${BANGDELIV_SECRET_DIR:-/opt/bangdeliv-secrets}"
FCM_SOURCE="${FCM_SOURCE:-/tmp/firebase-credentials-FCM.json}"
FCM_TARGET="${FCM_TARGET:-$SECRET_DIR/firebase-credentials-FCM.json}"
FCM_CONTAINER_PATH="${FCM_CONTAINER_PATH:-/run/secrets/firebase-credentials-FCM.json}"
PUBLIC_IP="${BANGDELIV_PUBLIC_IP:-43.129.55.16}"

cd "$APP_DIR"

if [[ ! -f docker-compose.yml ]]; then
    echo "docker-compose.yml tidak ditemukan. Jalankan script ini dari repo BangDeliv atau set APP_DIR."
    exit 1
fi

if [[ ! -f .env ]]; then
    echo ".env tidak ditemukan di $APP_DIR."
    exit 1
fi

if [[ ! -f "$FCM_TARGET" ]]; then
    if [[ ! -f "$FCM_SOURCE" ]]; then
        echo "Credential FCM tidak ditemukan."
        echo "Upload dulu ke VPS, contoh:"
        echo "  scp storage/app/firebase-credentials-FCM.json ubuntu@$PUBLIC_IP:$FCM_SOURCE"
        exit 1
    fi

    mkdir -p "$SECRET_DIR"
    cp "$FCM_SOURCE" "$FCM_TARGET"
fi

chmod 700 "$SECRET_DIR"
chmod 600 "$FCM_TARGET"

if command -v python3 >/dev/null 2>&1; then
    python3 -m json.tool "$FCM_TARGET" >/dev/null
fi

set_env_value() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        printf '\n%s=%s\n' "$key" "$value" >> .env
    fi
}

set_env_value "FIREBASE_CREDENTIALS" "$FCM_CONTAINER_PATH"
set_env_value "BROADCAST_CONNECTION" "reverb"
set_env_value "REVERB_HOST" "$PUBLIC_IP"
set_env_value "REVERB_PORT" "8080"
set_env_value "REVERB_SCHEME" "http"

cat > "$OVERRIDE_FILE" <<YAML
services:
  app:
    volumes:
      - "$FCM_TARGET:$FCM_CONTAINER_PATH:ro"

  queue:
    volumes:
      - "$FCM_TARGET:$FCM_CONTAINER_PATH:ro"

  reverb:
    volumes:
      - "$FCM_TARGET:$FCM_CONTAINER_PATH:ro"

  mysql:
    ports:
      - "127.0.0.1:3306:3306"
YAML

echo "Production runtime siap."
echo "Credential FCM host : $FCM_TARGET"
echo "Credential FCM app  : $FCM_CONTAINER_PATH"
echo "Override Compose    : $APP_DIR/$OVERRIDE_FILE"
echo
echo "Jalankan deploy ulang:"
echo "  cd $APP_DIR"
echo "  docker compose up -d --remove-orphans"
echo "  docker compose restart app queue reverb"
echo "  ./scripts/verify-production-realtime.sh"
