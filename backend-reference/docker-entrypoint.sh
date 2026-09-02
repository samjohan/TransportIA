#!/bin/sh
set -e

php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=DemoUsersSeeder --force
php artisan storage:link || true

# NOT `php artisan serve` — its ServeCommand reconstructs the environment
# for the child process it spawns from Laravel's parsed .env file, not from
# the container's real OS environment. That silently ignores DB_HOST/
# DB_PASSWORD/etc. set via `docker run -e` or a compose/Dokploy environment
# block unless those same values also happen to be baked into .env at
# image-build time — which defeats the point of setting them per-deployment
# (e.g. pointing the same image at a different environment's database).
# Invoking the built-in server directly is a normal child process that
# inherits the real environment like anything else.
#
# `artisan serve` actually runs this router script with its working
# directory set to public/, not the project root — the script's
# `require_once 'index.php'` is CWD-relative, not __DIR__-relative, so
# running it from /var/www looks for a nonexistent /var/www/index.php.
cd public
exec php -S 0.0.0.0:8000 /var/www/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
