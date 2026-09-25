# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: build front-end assets
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2: install PHP dependencies (no dev packages -> no Ignition)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# Scripts need the full app, so defer them; the runtime stage runs
# `composer dump-autoload`, which triggers package:discover.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 3: runtime image
# ---------------------------------------------------------------------------
FROM php:8.3-apache

# 1. Install development packages and clean up apt cache
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    curl \
    cron \
    && docker-php-ext-install pdo_mysql pdo_pgsql bcmath gd zip

# 2. Apache Configuration: Point DocumentRoot to /public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && a2enmod rewrite

# Directory listings are disabled; the committed public/.htaccess provides the
# front-controller rewrite. Dotfiles (except .well-known) are denied so
# .user.ini/.htaccess are never served.
RUN echo '<Directory /var/www/html/public>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<FilesMatch "^[.](?!well-known)">\n\
    Require all denied\n\
</FilesMatch>' >> /etc/apache2/apache2.conf

# 3. Install Composer and PHP limits
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php/conf.d/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

# 4. Copy the application, then overlay the built dependencies and assets so
#    the image can boot on its own (previously it only worked via a bind mount).
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# 5. Finish autoloading (runs package:discover) and prepare writable dirs
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# 6. Set up the Laravel scheduler with cron
RUN echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" | crontab -

# 7. Startup script runs both Apache and cron
RUN echo '#!/bin/bash\n\
mkdir -p /var/www/html/storage/framework/cache /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/storage/logs /var/www/html/bootstrap/cache\n\
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache\n\
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache\n\
service cron start\n\
apache2-foreground' > /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

# Safe production defaults; the platform's environment can override these.
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=daily

EXPOSE 80

CMD ["/usr/local/bin/start.sh"]
