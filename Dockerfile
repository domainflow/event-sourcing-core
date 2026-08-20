FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
    git zip unzip curl libzip-dev libpcre3-dev \
    pkg-config libssl-dev autoconf build-essential \
    libonig-dev \
    && docker-php-ext-install zip pdo pdo_mysql mbstring \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

RUN apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-interaction --prefer-dist
