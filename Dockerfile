FROM composer:2 AS composer

FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev \
    && docker-php-ext-install mbstring pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY config/php-upload.ini /usr/local/etc/php/conf.d/zzz-fct-upload.ini
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY . /var/www/html

RUN mkdir -p /var/lib/fct/storage \
    && chown -R www-data:www-data /var/lib/fct/storage \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod 700 /var/lib/fct/storage

ENV FCT_STORAGE_PATH=/var/lib/fct/storage

WORKDIR /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
