# BallPicker staging — PHP-FPM app image with the backend code BAKED IN
# (no bind mounts: Docker Desktop file sharing of ~/Documents is not needed).
# Build context: the staging git worktree root (STAGING_WORKTREE).
# PHP 8.3 = production php8.3-fpm. Extensions = what the backend uses.
FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
      libzip-dev libicu-dev libonig-dev unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd exif mbstring zip intl bcmath opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

RUN { \
      echo "upload_max_filesize=20M"; \
      echo "post_max_size=25M"; \
      echo "memory_limit=512M"; \
      echo "expose_php=Off"; \
    } > /usr/local/etc/php/conf.d/ballpicker-staging.ini

WORKDIR /var/www/backend

# Dependencies first (layer cache), production set only.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
 && rm -f .env \
 && chown -R www-data:www-data bootstrap/cache

# storage/ is a named volume; the entrypoint creates the tree and fixes owners.
COPY --from=staging --chmod=0755 staging-entrypoint.sh /usr/local/bin/staging-entrypoint.sh
ENTRYPOINT ["staging-entrypoint.sh"]
CMD ["php-fpm"]
