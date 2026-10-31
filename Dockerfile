FROM php:8.2-apache

# Install dependencies and extensions
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_mysql mysqli

# Ensure Apache owns the web root AND the session storage directory
RUN mkdir -p /var/lib/php/sessions \
    && chown -R www-data:www-data /var/lib/php/sessions /var/www/html \
    && chmod -R 777 /var/lib/php/sessions

COPY . /var/www/html/

EXPOSE 80