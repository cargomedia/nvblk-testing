FROM php:7.4-fpm

WORKDIR /var/tmp/
COPY docker/images/app/php-extensions ./

RUN apt-get update && apt-get install -y \
    apt-transport-https curl \
    libssl-dev pkg-config vim netcat \
    libtool build-essential automake \
    unzip libzip-dev \
    && (echo '' | pecl install apcu) && docker-php-ext-enable --ini-name apcu.ini apcu \
    && rm -rf /usr/local/etc/php/php.ini-* /usr/local/etc/php-fpm* && mv apc-status.php opcache-status.php  /usr/local/bin/ \
    && mv -f conf.d/* /usr/local/etc/php/conf.d/ && mv -f php-fpm.conf /usr/local/etc/ && mv -f php.ini /usr/local/etc/php/ \
    && apt-get clean && rm -rf /var/lib/apt/lists/* && rm -rf /var/tmp/* && rm -rf /tmp/pear;
WORKDIR /opt/app
COPY ./docker/entrypoint docker/entrypoint
COPY neverblock/backend_loader.php public/backend_loader.php

ADD https://github.com/krallin/tini/releases/download/v0.18.0/tini /usr/local/bin/tini
RUN chmod +x /usr/local/bin/tini

