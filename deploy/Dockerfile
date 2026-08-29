# Build/runtime image for the Laravel app (local + staging).
FROM php:8.2-fpm-alpine

RUN apk add --no-cache git curl icu-dev oniguruma-dev libzip-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql intl mbstring zip bcmath opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer deps first for layer caching.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist || true

COPY . .
RUN composer dump-autoload --optimize || true \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000
CMD ["php-fpm"]
