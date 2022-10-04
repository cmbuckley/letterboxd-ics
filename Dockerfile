ARG PHP_VERSION=8.1
FROM php:${PHP_VERSION}-apache

RUN apt-get update \
    && apt-get install -y libzip-dev \
    && docker-php-ext-install zip

ENV APACHE_DOCUMENT_ROOT /app/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY . /app
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
RUN composer install --optimize-autoloader --no-dev
