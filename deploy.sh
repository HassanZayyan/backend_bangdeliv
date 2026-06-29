#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/opt/bangdeliv}"
BRANCH="${DEPLOY_BRANCH:-main}"

cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "Missing $APP_DIR/.env. Copy .env.docker.example to .env and fill production values first." >&2
    exit 1
fi

APP_KEY_VALUE="$(awk '/^APP_KEY=/{value=substr($0, 9); gsub(/"/, "", value); print value; exit}' .env)"
if [ -z "$APP_KEY_VALUE" ]; then
    echo "Generating APP_KEY in $APP_DIR/.env..."
    if command -v openssl >/dev/null 2>&1; then
        NEW_APP_KEY="base64:$(openssl rand -base64 32)"
    elif command -v python3 >/dev/null 2>&1; then
        NEW_APP_KEY="$(python3 -c 'import base64, secrets; print("base64:" + base64.b64encode(secrets.token_bytes(32)).decode())')"
    else
        echo "Cannot generate APP_KEY because neither openssl nor python3 is available." >&2
        exit 1
    fi

    if grep -q '^APP_KEY=' .env; then
        sed -i "s|^APP_KEY=.*|APP_KEY=$NEW_APP_KEY|" .env
    else
        printf '\nAPP_KEY=%s\n' "$NEW_APP_KEY" >> .env
    fi
fi

echo "Fetching origin/$BRANCH..."
git fetch origin "$BRANCH"
git reset --hard "origin/$BRANCH"

echo "Building Docker images..."
docker compose build --pull

echo "Starting services..."
docker compose up -d --remove-orphans

echo "Waiting for MySQL..."
until docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h 127.0.0.1 -u root --silent'; do
    sleep 2
done

echo "Running Laravel deployment commands..."
docker compose exec -T app php artisan migrate --seed --force
docker compose exec -T app php artisan storage:link
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "Restarting runtime workers..."
docker compose restart app queue reverb
docker compose restart nginx

echo "Cleaning old Docker images..."
docker image prune -f >/dev/null

echo "BangDeliv deploy complete."
