FROM composer:2 AS composer

FROM node:22-alpine AS frontend-build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY tsconfig.json vite.config.ts postcss.config.cjs ./
COPY frontend ./frontend
COPY public/assets/css ./public/assets/css
RUN npm run build

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev unzip \
    && docker-php-ext-install mbstring pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY config/php-upload.ini /usr/local/etc/php/conf.d/zzz-fct-upload.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/storage-entrypoint.sh /usr/local/bin/fct-storage-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/fct-storage-entrypoint
COPY . /var/www/html
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY --from=frontend-build /app/public/build /var/www/html/public/build

RUN mkdir -p /var/lib/fct/storage \
    && chmod 755 /usr/local/bin/fct-storage-entrypoint \
    && chown -R www-data:www-data /var/lib/fct/storage \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod 700 /var/lib/fct/storage

ENV FCT_STORAGE_PATH=/var/lib/fct/storage

ENTRYPOINT ["/usr/local/bin/fct-storage-entrypoint"]

WORKDIR /var/www/html

EXPOSE 80

CMD ["sh", "-c", "php scripts/migrate.php && exec apache2-foreground"]
