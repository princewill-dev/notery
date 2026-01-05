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
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf && a2enmod rewrite

RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# 3. Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY php/conf.d/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# 4. Set up Laravel scheduler with cron
RUN echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" | crontab -

# 5. Create startup script to run both Apache and cron
RUN echo '#!/bin/bash\n\
service cron start\n\
apache2-foreground' > /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

# 6. Set working directory
WORKDIR /var/www/html

CMD ["/usr/local/bin/start.sh"]