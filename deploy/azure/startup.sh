#!/usr/bin/env bash
#
# FirstMaket — Azure App Service startup command.
#
# Set this as the App Service "Startup Command":
#     /home/site/wwwroot/deploy/azure/startup.sh
#
# App Service starts nginx and php-fpm with its own defaults, then runs this.
# So the job here is to correct the web root, make sure the persistent storage
# tree exists, and bring the application into a serving state.
#
# It runs on every container start, not just on deploy — a restart, a scale
# event, or Azure moving the instance. Everything below is therefore written
# to be safe to repeat.

set -euo pipefail

WWWROOT=/home/site/wwwroot

# Kept outside wwwroot on purpose. Deployment replaces wwwroot wholesale, so
# anything written inside it — product images, CAC documents, logs — is lost
# on the next push. /home is the persistent Azure Storage mount and is not
# touched by a deploy. Laravel is pointed here by the LARAVEL_STORAGE_PATH
# app setting; this must match it.
STORAGE=/home/data/storage

echo "==> Web root"
# Without this App Service serves wwwroot itself, which both fails to find
# Laravel's front controller and publishes .env and vendor/ to the internet.
cp "$WWWROOT/deploy/azure/nginx-default.conf" /etc/nginx/sites-available/default

echo "==> Persistent storage tree"
# Laravel does not create these, it expects them. A missing framework/views
# is a fatal error on the first page render, and a missing logs directory
# means the error explaining that is never written down.
mkdir -p \
    "$STORAGE/app/public" \
    "$STORAGE/app/private" \
    "$STORAGE/framework/cache/data" \
    "$STORAGE/framework/sessions" \
    "$STORAGE/framework/testing" \
    "$STORAGE/framework/views" \
    "$STORAGE/logs"

chown -R www-data:www-data "$STORAGE"
chmod -R 775 "$STORAGE"

cd "$WWWROOT"

echo "==> Public storage symlink"
# public/storage -> $STORAGE/app/public. Recreated every deploy, because
# wwwroot is replaced wholesale and the link is not in git.
#
# The directory is removed first. `storage:link --force` only replaces a
# target that is already a symlink — against a real directory it prints
# "The [public/storage] link already exists" and gives up, leaving no link
# at all and every product image 404ing. That is exactly what a deploy
# produces if anything ships a public/storage directory.
if [ -d "$WWWROOT/public/storage" ] && [ ! -L "$WWWROOT/public/storage" ]; then
    echo "    removing a real public/storage directory left by the deploy"
    rm -rf "$WWWROOT/public/storage"
fi

php artisan storage:link --force

# A missing link means uploads silently 404, which is easy to mistake for a
# broken upload form. Fail the start instead.
test -L "$WWWROOT/public/storage" || {
    echo "    public/storage is not a symlink — uploads would 404"
    exit 1
}

echo "==> Migrations"
# --force because this is non-interactive. Migrations are idempotent, so
# re-running on a restart is a no-op; on a single B1 instance there is no
# second container to race with. Revisit if this ever scales out.
php artisan migrate --force

echo "==> Caches"
# Cleared first: a config cache left over from the previous release would
# otherwise survive and quietly serve the old settings.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Reload nginx"
nginx -t
service nginx reload

echo "==> Background workers"
# App Service does not provide Laravel's queue or scheduler automatically.
# Keep one worker and one scheduler per instance; the pgrep guards make this
# startup script safe to run again after an App Service restart.
if ! pgrep -f "artisan queue:work" >/dev/null 2>&1; then
    nohup php artisan queue:work database --sleep=3 --tries=3 --timeout=120 \
        >> "$STORAGE/logs/queue-worker.log" 2>&1 &
fi

if ! pgrep -f "artisan schedule:work" >/dev/null 2>&1; then
    nohup php artisan schedule:work \
        >> "$STORAGE/logs/scheduler.log" 2>&1 &
fi

echo "==> FirstMaket is up"
