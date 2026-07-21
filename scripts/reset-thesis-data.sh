#!/usr/bin/env bash
set -Eeuo pipefail

# Reset database production ke dataset skripsi (ThesisDatasetSeeder) dalam satu
# perintah, sekaligus memastikan FCM dan Reverb tetap sehat setelah reset.
# Aman diulang kapan pun — setelah selesai testing, jalankan lagi untuk kembali
# ke kondisi seeder:
#
#   cd /opt/bangdeliv && bash scripts/reset-thesis-data.sh
#
# Catatan: personal access token ikut terhapus, jadi semua user (customer,
# driver, admin) harus login ulang di aplikasi setelah reset.

APP_DIR="${APP_DIR:-/opt/bangdeliv}"

cd "$APP_DIR"

if [ ! -f .env ]; then
    echo "Missing $APP_DIR/.env. Copy .env.docker.example to .env and fill production values first." >&2
    exit 1
fi

echo "Starting services..."
docker compose up -d --remove-orphans

echo "Waiting for MySQL..."
until docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h 127.0.0.1 -u root --silent'; do
    sleep 2
done

echo "Rebuilding database from the thesis dataset..."
docker compose exec -T app php artisan migrate:fresh --seed --force
docker compose exec -T app php artisan storage:link

echo "Refreshing config cache (keeping FCM credentials path intact)..."
docker compose exec -T app sh -lc '
if [ -f /var/www/html/storage/app/private/firebase/firebase-credentials.json ]; then
    export FIREBASE_CREDENTIALS=/var/www/html/storage/app/private/firebase/firebase-credentials.json
    export GOOGLE_APPLICATION_CREDENTIALS="$FIREBASE_CREDENTIALS"
fi
php artisan config:clear
php artisan config:cache
'

echo "Restarting runtime workers so queue + websocket use the fresh database..."
docker compose restart app queue reverb

echo
echo "Dataset summary:"
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h 127.0.0.1 -u root -t "$MYSQL_DATABASE"' <<'SQL'
SELECT 'users' AS tabel, COUNT(*) AS jumlah FROM users
UNION ALL SELECT 'drivers', COUNT(*) FROM drivers
UNION ALL SELECT 'driver_documents', COUNT(*) FROM driver_documents
UNION ALL SELECT 'orders', COUNT(*) FROM orders
UNION ALL SELECT 'menus', COUNT(*) FROM menus
UNION ALL SELECT 'device_tokens', COUNT(*) FROM device_tokens
UNION ALL SELECT 'shopping_items_null_menu', COUNT(*) FROM shopping_order_items WHERE item_source = 'MENU_DB' AND menu_id IS NULL;
SQL

echo
echo "Reset selesai. Cek realtime dengan: bash scripts/verify-production-realtime.sh"
