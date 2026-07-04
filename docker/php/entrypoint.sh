#!/usr/bin/env sh
set -e

mkdir -p \
    /shared/public \
    bootstrap/cache \
    storage/app/private/firebase \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

prepare_firebase_credentials() {
    source_path="${FIREBASE_CREDENTIALS:-${GOOGLE_APPLICATION_CREDENTIALS:-}}"
    runtime_path="${FIREBASE_RUNTIME_CREDENTIALS:-/var/www/html/storage/app/private/firebase/firebase-credentials.json}"

    if [ -n "$source_path" ] && [ -f "$source_path" ] && [ "$source_path" != "$runtime_path" ]; then
        mkdir -p "$(dirname "$runtime_path")"
        cp "$source_path" "$runtime_path"
    fi

    if [ -f "$runtime_path" ]; then
        chown www-data:www-data "$runtime_path" 2>/dev/null || true
        chmod 0400 "$runtime_path" 2>/dev/null || true
        export FIREBASE_CREDENTIALS="$runtime_path"
        export GOOGLE_APPLICATION_CREDENTIALS="$runtime_path"
    fi
}

prepare_firebase_credentials

if [ -d /opt/bangdeliv-seed-public ]; then
    rsync -a --ignore-existing /opt/bangdeliv-seed-public/ storage/app/public/
fi

if [ -e public/storage ] && [ ! -L public/storage ]; then
    rm -rf public/storage
fi

if [ ! -L public/storage ]; then
    ln -s ../storage/app/public public/storage
fi

rsync -a --delete public/ /shared/public/
chown -R www-data:www-data /shared/public bootstrap/cache storage

exec "$@"
