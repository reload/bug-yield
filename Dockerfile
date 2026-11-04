FROM composer:2 AS composer
FROM phusion/baseimage:focal-1.2.0

RUN install_clean php-cli php-curl php-xml php-mbstring php-gmp git unzip

COPY ./ /bug-yield/

COPY --from=composer /usr/bin/composer /usr/bin/composer


RUN cd /bug-yield && composer install && \
        crontab /bug-yield/docker/crontab

WORKDIR /bug-yield
