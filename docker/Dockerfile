FROM php:8.3-apache

# pdo_mysql for the database, zip for .docx import. Everything else the app
# uses (dom, fileinfo, mbstring, json) ships with the official image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Honour the project's .htaccess files, like typical shared hosting does.
RUN sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && a2enmod headers

COPY php.ini /usr/local/etc/php/conf.d/ajaia.ini
