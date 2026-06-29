#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/opt/bangdeliv}"
BRANCH="${DEPLOY_BRANCH:-main}"
LOCK_FILE="${LOCK_FILE:-/tmp/bangdeliv-auto-deploy.lock}"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "Another deploy is already running."
    exit 0
fi

cd "$APP_DIR"

CURRENT_COMMIT="$(git rev-parse HEAD)"
git fetch origin "$BRANCH" --quiet
REMOTE_COMMIT="$(git rev-parse "origin/$BRANCH")"

if [ "$CURRENT_COMMIT" = "$REMOTE_COMMIT" ]; then
    echo "No new commit on origin/$BRANCH."
    exit 0
fi

echo "New commit detected: $CURRENT_COMMIT -> $REMOTE_COMMIT"
"$APP_DIR/deploy.sh"

