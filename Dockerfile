FROM composer:2 AS composer
FROM php:8.5-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        cron git unzip util-linux libcurl4-openssl-dev libonig-dev libxml2-dev libgmp-dev && \
    docker-php-ext-install curl gmp mbstring xml && \
    rm -rf /var/lib/apt/lists/*

COPY ./ /bug-yield/

COPY --from=composer /usr/bin/composer /usr/bin/composer


RUN cd /bug-yield && composer install && \
        crontab /bug-yield/docker/crontab

WORKDIR /bug-yield

ENTRYPOINT ["/bug-yield/docker/entrypoint.sh"]
CMD ["cron", "-f"]
