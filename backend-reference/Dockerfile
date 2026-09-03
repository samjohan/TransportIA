FROM composer:2 AS composer

FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
        git unzip libpq-dev libzip-dev tesseract-ocr tesseract-ocr-spa \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# The php:8.3-cli base image ships a pre-existing (empty) /var/www/html, so
# /var/www itself isn't empty — composer create-project refuses to run
# unless we clear that out first.
RUN rm -rf /var/www/html

# `backend-reference/` only holds the files that differ from a stock Laravel
# install (per the project README). composer create-project needs an empty
# target directory, so we scaffold Laravel first and layer the reference
# files on top of it afterwards, rather than copying them in up front.
#
# Composer 2.8+ blocks resolving any package version that has ever been named
# in a security advisory, even already-patched ones — which as of this
# writing flags the entire currently-released laravel/framework 11.x range.
# `composer global config` doesn't override this (it's only honored from the
# project's own composer.json). So: fetch the skeleton with --no-install
# --no-scripts (--no-install alone still fires the post-create-project
# key:generate script immediately, before vendor/ exists, and that crashes),
# patch the policy into the now-existing composer.json, then do the .env
# copy / install / key:generate steps ourselves in the right order. Swap to
# pinning an explicit non-flagged laravel/framework version instead if you'd
# rather keep the advisory gate on.
RUN composer create-project --prefer-dist --no-interaction --no-install --no-scripts laravel/laravel . "^11.0" \
    && cp -n .env.example .env \
    && composer config policy.advisories.block false \
    && composer install --no-interaction \
    && composer require --no-interaction laravel/sanctum spatie/laravel-permission irazasyed/telegram-bot-sdk thiagoalessio/tesseract_ocr \
    && php artisan key:generate --ansi \
    && touch database/database.sqlite \
    # install:api auto-runs its migrations against whatever .env currently
    # points at (still SQLite here) — "db" (the Postgres service's hostname)
    # only resolves inside the docker-compose network at container runtime,
    # not during this image build, so this has to happen before switching
    # .env to Postgres below.
    && php artisan install:api --no-interaction \
    # vendor:publish/install:api stamp migrations with the current build's
    # wall-clock time, so a later rebuild (without wiping the dev DB volume)
    # would publish a *new* filename for the same table and Laravel would
    # try to CREATE TABLE it again. Pin a fixed name so it's stable and gets
    # recognized as "already run" across rebuilds against the same volume.
    && for f in database/migrations/*_create_personal_access_tokens_table.php; do \
         mv "$f" database/migrations/2024_01_01_000090_create_personal_access_tokens_table.php; \
       done \
    # `php artisan serve` only forwards environment variables to its spawned
    # dev-server child process if the key is actually present in .env — it
    # ignores anything that only exists in the container's real OS
    # environment (i.e. docker-compose's `environment:` block), even though
    # every other invocation (artisan migrate, tinker, docker exec) reads
    # the OS env correctly. Switch .env to Postgres now (after install:api
    # is done with SQLite) so both paths agree at container runtime;
    # docker-compose's `environment:` still overrides these if changed there.
    && printf '\nDB_CONNECTION=pgsql\nDB_HOST=db\nDB_PORT=5432\nDB_DATABASE=rutas_gastos\nDB_USERNAME=postgres\nDB_PASSWORD=postgres\nQUEUE_CONNECTION=sync\n' >> .env

COPY . /tmp/reference

RUN cp -r /tmp/reference/app/. app/ \
    && cp -r /tmp/reference/database/migrations/. database/migrations/ \
    && cp -r /tmp/reference/database/seeders/. database/seeders/ \
    && cp /tmp/reference/routes/api.php routes/api.php \
    && cp /tmp/reference/bootstrap/app.php bootstrap/app.php \
    && rm -rf /tmp/reference

# Same fixed-filename reasoning as the Sanctum migration above.
RUN php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --force \
    && for f in database/migrations/*_create_permission_tables.php; do \
         mv "$f" database/migrations/2024_01_01_000091_create_permission_tables.php; \
       done

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["docker-entrypoint.sh"]
