FROM php:8.3-apache-bookworm

# Cliente nativo de MariaDB para streaming de respaldos sin saturar memoria en PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# Conector nativo PDO y mysqli
RUN docker-php-ext-install pdo pdo_mysql mysqli && a2enmod rewrite

WORKDIR /var/www/html