#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
OVERRIDE_FILE="${OVERRIDE_FILE:-docker-compose.override.yml}"
READONLY_USER="${DBEAVER_READONLY_USER:-bangdeliv_readonly}"

cd "$APP_DIR"

if [[ ! -f docker-compose.yml ]]; then
    echo "docker-compose.yml tidak ditemukan. Jalankan script ini dari repo BangDeliv atau set APP_DIR."
    exit 1
fi

if [[ ! -f .env ]]; then
    echo ".env tidak ditemukan di $APP_DIR."
    exit 1
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker belum terpasang atau tidak ada di PATH."
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose plugin tidak tersedia."
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl tidak tersedia. Install dulu openssl untuk generate password."
    exit 1
fi

if [[ ! "$READONLY_USER" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "DBEAVER_READONLY_USER hanya boleh berisi huruf, angka, dan underscore."
    exit 1
fi

if [[ ! -f "$OVERRIDE_FILE" ]]; then
    cat > "$OVERRIDE_FILE" <<'YAML'
services:
  mysql:
    ports:
      - "127.0.0.1:3306:3306"
YAML
elif grep -q '127.0.0.1:3306:3306' "$OVERRIDE_FILE"; then
    :
elif grep -Eq '^[[:space:]]+mysql:' "$OVERRIDE_FILE"; then
    echo "$OVERRIDE_FILE sudah punya service mysql, tapi belum berisi bind 127.0.0.1:3306:3306."
    echo "Periksa manual agar konfigurasi Docker lain tidak tertimpa."
    exit 1
elif grep -Eq '^services:[[:space:]]*$' "$OVERRIDE_FILE"; then
    cat >> "$OVERRIDE_FILE" <<'YAML'

  mysql:
    ports:
      - "127.0.0.1:3306:3306"
YAML
else
    echo "$OVERRIDE_FILE sudah ada tapi formatnya tidak dikenali."
    echo "Periksa manual agar konfigurasi Docker lain tidak tertimpa."
    exit 1
fi

echo "Override Docker dibuat: $OVERRIDE_FILE"
docker compose up -d mysql

echo "Memeriksa bind port MySQL..."
if ss -ltn | grep -Eq '0\.0\.0\.0:3306|\[::\]:3306|\*:3306'; then
    echo "Tidak aman: port 3306 terbind ke publik. Periksa $OVERRIDE_FILE."
    ss -ltn | grep 3306 || true
    exit 1
fi

if ! ss -ltn | grep -q '127.0.0.1:3306'; then
    echo "Port 3306 belum terlihat di 127.0.0.1. Status listen saat ini:"
    ss -ltn | grep 3306 || true
    exit 1
fi

echo "Menunggu MySQL siap menerima koneksi..."
MYSQL_READY=0
for _ in {1..60}; do
    if docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqladmin ping -h 127.0.0.1 -uroot --silent' >/dev/null 2>&1; then
        MYSQL_READY=1
        break
    fi

    sleep 2
done

if [[ "$MYSQL_READY" != "1" ]]; then
    echo "MySQL belum siap setelah 120 detik. Log terakhir container mysql:"
    docker compose logs --tail=80 mysql || true
    exit 1
fi

DB_DATABASE="$(docker compose exec -T mysql sh -c 'printf "%s" "$MYSQL_DATABASE"')"
if [[ -z "$DB_DATABASE" || ! "$DB_DATABASE" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Nama database dari container tidak valid: $DB_DATABASE"
    exit 1
fi

READONLY_PASSWORD="${DBEAVER_READONLY_PASSWORD:-$(openssl rand -base64 24)}"
if [[ ! "$READONLY_PASSWORD" =~ ^[A-Za-z0-9_+=.@:/-]+$ ]]; then
    echo "DBEAVER_READONLY_PASSWORD hanya boleh berisi huruf, angka, dan karakter _+=.@:/-"
    exit 1
fi

docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -h 127.0.0.1 -uroot' <<SQL
CREATE USER IF NOT EXISTS '${READONLY_USER}'@'%' IDENTIFIED BY '${READONLY_PASSWORD}';
ALTER USER '${READONLY_USER}'@'%' IDENTIFIED BY '${READONLY_PASSWORD}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${READONLY_USER}'@'%';
GRANT SELECT, SHOW VIEW ON \`${DB_DATABASE}\`.* TO '${READONLY_USER}'@'%';
FLUSH PRIVILEGES;
SQL

docker compose exec -T mysql sh -c 'MYSQL_PWD="$1" mysql -h 127.0.0.1 -u"$2" "$3" -e "SHOW TABLES;" >/dev/null' _ "$READONLY_PASSWORD" "$READONLY_USER" "$DB_DATABASE"

cat <<EOF

DBeaver read-only user siap.

Database host di DBeaver : 127.0.0.1
Database port            : 3306
Database name            : $DB_DATABASE
Username                 : $READONLY_USER
Password                 : $READONLY_PASSWORD

SSH tunnel DBeaver:
Host/IP                  : 43.129.55.16
Port                     : 22
User                     : ubuntu

Simpan password ini di tempat aman. Script tidak menyimpannya ke file.
EOF
