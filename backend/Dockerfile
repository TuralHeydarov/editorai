FROM php:8.2-fpm

# Install system deps
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpng-dev libonig-dev libxml2-dev libsqlite3-dev libpq-dev \
    && docker-php-ext-install pdo pdo_sqlite pdo_pgsql mbstring xml bcmath gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# PHP config for large uploads
RUN echo "upload_max_filesize=512M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size=512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit=512M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time=300" >> /usr/local/etc/php/conf.d/uploads.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

# Laravel package discovery needs these writable directories during Composer install.
RUN mkdir -p /var/www/storage/app/public/videos \
    && mkdir -p /var/www/storage/framework/sessions \
    && mkdir -p /var/www/storage/framework/views \
    && mkdir -p /var/www/storage/framework/cache/data \
    && mkdir -p /var/www/storage/logs \
    && mkdir -p /var/www/bootstrap/cache \
    && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache /var/www/database \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache /var/www/database

USER www-data
ENV APP_ENV=production APP_DEBUG=false
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
