FROM phusion/baseimage:0.11

COPY ./ /bug-yield/

RUN install_clean php-cli php-curl php-xml php-mbstring git unzip


# Link output into /var/www/html.

RUN curl -sS https://getcomposer.org/installer | php && \
    mv composer.phar /usr/local/bin/composer && \
    cd /bug-yield && composer install && \
    crontab /bug-yield/docker/crontab

WORKDIR /bug-yield
