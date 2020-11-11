FROM composer:2 AS composer
FROM phusion/baseimage:0.11

RUN install_clean php-cli php-curl php-xml php-mbstring git unzip

COPY ./ /bug-yield/

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /bug-yield
