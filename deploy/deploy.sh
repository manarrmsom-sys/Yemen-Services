#!/usr/bin/env bash
#
# Zero-downtime release activation. Runs ON THE VPS, invoked by
# .github/workflows/deploy.yml after the release tarball is unpacked into
# $RELEASE (which contains ./platform and ./deploy).
#
# Required env: DEPLOY_PATH (e.g. /var/www/yemen-services), RELEASE
# Layout:  $DEPLOY_PATH/releases/<ts>/platform   +   $DEPLOY_PATH/shared   +   $DEPLOY_PATH/current -> releases/<ts>/platform
#
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${RELEASE:?RELEASE is required}"

APP="$RELEASE/platform"
SHARED="$DEPLOY_PATH/shared"
KEEP_RELEASES=5
PHP_FPM_SERVICE="php8.2-fpm"

echo "▶ Activating release: $APP"

mkdir -p "$SHARED/storage" "$SHARED/storage/app/kyc"

# --- shared, persistent state (never in a release) ---
if [ ! -f "$SHARED/.env" ]; then
    echo "✗ $SHARED/.env is missing. Create it from platform/.env.example before the first deploy." >&2
    exit 1
fi
ln -sfn "$SHARED/.env" "$APP/.env"

# Move the release's empty storage aside, symlink the shared one.
rm -rf "$APP/storage"
ln -sfn "$SHARED/storage" "$APP/storage"

# --- dependencies (Composer only; assets were built in CI) ---
cd "$APP"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# --- database + caches ---
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

# --- flip the symlink atomically ---
ln -sfn "$APP" "$DEPLOY_PATH/current"

# --- reload workers + PHP-FPM ---
php artisan queue:restart
sudo systemctl reload "$PHP_FPM_SERVICE"
sudo supervisorctl restart yemen-queue:* || true

# --- prune old releases ---
cd "$DEPLOY_PATH/releases"
ls -1dt */ | tail -n +$((KEEP_RELEASES + 1)) | xargs -r rm -rf

php artisan up || true
echo "✓ Deployed $(basename "$RELEASE")"
