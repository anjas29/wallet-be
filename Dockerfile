FROM php:8.4-cli

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev libxml2-dev libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql pdo_mysql zip intl bcmath gd exif \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Same path the compose files use as working_dir. Local dev bind-mounts the source over it;
# the VPS runs the baked-in code and only mounts .env and storage/.
WORKDIR /var/www/html

# Dependencies first so this layer stays cached until composer.lock changes.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-progress --no-dev --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && php artisan storage:link

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
