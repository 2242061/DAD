FROM php:8.5-cli-alpine
RUN apk add --no-cache sqlite-dev oniguruma-dev libxml2-dev && docker-php-ext-install pdo_sqlite mbstring dom xml
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts && php artisan package:discover
EXPOSE 8000
CMD ["sh", "-c", "touch /data/database.sqlite && php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=8000"]
