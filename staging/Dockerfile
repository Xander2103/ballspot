# PHP-FPM image for the local BallPicker staging stack (docs/clubs-staging-plan.md).
# PHP 8.3 = the production php8.3-fpm major/minor. Extensions are the ones the
# backend uses: pdo_mysql (MariaDB), gd with JPEG/PNG/WebP (image re-encoding,
# EXIF stripping), exif, mbstring, zip (media export), intl, bcmath, opcache.
FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libjpeg62-turbo-dev libpng-dev libwebp-dev libfreetype6-dev \
      libzip-dev libicu-dev libonig-dev unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd exif mbstring zip intl bcmath opcache \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Production-like limits for uploads (club photos up to 15 MB).
RUN { \
      echo "upload_max_filesize=20M"; \
      echo "post_max_size=25M"; \
      echo "memory_limit=512M"; \
      echo "expose_php=Off"; \
    } > /usr/local/etc/php/conf.d/ballpicker-staging.ini

WORKDIR /var/www/backend
