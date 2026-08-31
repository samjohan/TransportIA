#!/bin/sh
set -e

php artisan migrate --force
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=DemoUsersSeeder --force
php artisan storage:link || true

exec php artisan serve --host=0.0.0.0 --port=8000
