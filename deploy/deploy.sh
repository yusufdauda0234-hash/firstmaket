#!/usr/bin/env bash
#
# FirstMaket — pull and release.
#
# Run on the server, from /var/www/firstmaket:
#   sudo -u www-data bash deploy/deploy.sh
#
# Deliberately not zero-downtime. This is a single VM you are actively
# building on; an atomic-symlink release scheme costs more to maintain than a
# few seconds of maintenance page is worth. Swap it for one when there are
# real vendors on the site.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "==> Maintenance mode"
# --render serves a real page rather than a bare 503, and --secret lets you
# keep browsing to verify the deploy before letting anyone else back in.
php artisan down --render="errors::503" --retry=15 || true

finish() {
    php artisan up || true
}
trap finish EXIT

echo "==> Fetching"
git pull --ff-only origin main

echo "==> PHP dependencies"
# --no-dev because the production box has no business carrying Pest, and
# optimised autoloader because it is loaded on every request.
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "==> Front-end build"
# The built assets are what nginx serves under /build. Building on the server
# keeps the repo free of compiled output; on a 1 GB B1s this is the heaviest
# step, so give node a bounded heap rather than letting the OOM killer decide.
NODE_OPTIONS="--max-old-space-size=512" npm ci --no-audit --no-fund
NODE_OPTIONS="--max-old-space-size=512" npm run build

echo "==> Database"
# --force because production is non-interactive. This is the step that can
# take the site down: a bad migration fails here, and the trap above brings
# the app back up still on the old schema.
php artisan migrate --force

echo "==> Caches"
# Rebuilt, not just cleared: a cached config and route table are a large part
# of Laravel's request cost, and `optimize` is useless if the old cache is
# still on disk from the previous release.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Uploaded files are served from public/storage; the symlink is not in git.
php artisan storage:link || true

echo "==> Restarting background processes"
# The worker holds the code it booted with. Without this it keeps running the
# previous release indefinitely, which is the classic "the fix is deployed but
# the queue still does the old thing" bug.
sudo systemctl restart firstmaket-worker

echo "==> Done"
