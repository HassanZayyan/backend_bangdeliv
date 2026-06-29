#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/opt/bangdeliv}"
BRANCH="${DEPLOY_BRANCH:-main}"

cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "Missing $APP_DIR/.env. Copy .env.docker.example to .env and fill production values first." >&2
    exit 1
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

APP_KEY_VALUE="$(grep -E '^APP_KEY=' .env | head -n 1 | cut -d '=' -f 2- | tr -d '\"')"
if [ -z "$APP_KEY_VALUE" ]; then
    echo "Generating APP_KEY..."
    docker compose exec -T app php artisan key:generate --force
fi

echo "Running Laravel deployment commands..."
docker compose exec -T app php artisan migrate --seed --force
docker compose exec -T app php artisan storage:link
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "Restarting runtime workers..."
docker compose restart app queue reverb

echo "Cleaning old Docker images..."
docker image prune -f >/dev/null

echo "BangDeliv deploy complete."
