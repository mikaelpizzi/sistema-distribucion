FROM php:8.3-apache-bookworm

# Conector nativo PDO y mysqli
RUN docker-php-ext-install pdo pdo_mysql mysqli && a2enmod rewrite

WORKDIR /var/www/html