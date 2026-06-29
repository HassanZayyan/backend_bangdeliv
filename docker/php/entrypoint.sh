#!/usr/bin/env sh
set -e

mkdir -p \
    /shared/public \
    bootstrap/cache \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ -e public/storage ] && [ ! -L public/storage ]; then
    rm -rf public/storage
fi

if [ ! -L public/storage ]; then
    ln -s ../storage/app/public public/storage
fi

rsync -a --delete public/ /shared/public/
chown -R www-data:www-data /shared/public bootstrap/cache storage

exec "$@"

