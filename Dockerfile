FROM phusion/baseimage:0.11

RUN install_clean php-cli php-curl php-xml php-mbstring git unzip

COPY ./ /bug-yield/

RUN curl -o /tmp/composer-installer https://getcomposer.org/installer && \
        curl -o /tmp/composer-installer.sig https://composer.github.io/installer.sig &&  \
        php -r "if (hash('SHA384', file_get_contents('/tmp/composer-installer')) !== trim(file_get_contents('/tmp/composer-installer.sig'))) { unlink('/tmp/composer-installer'); echo 'Invalid installer' . PHP_EOL; exit(1); }" && \
        php /tmp/composer-installer --version=2.0.3 --filename=composer --install-dir=/usr/local/bin && \
        php -r "unlink('/tmp/composer-installer');" && \
        php -r "unlink('/tmp/composer-installer.sig');" && \
        cd /bug-yield && composer install --no-dev --no-progress --no-interaction && \
        crontab /bug-yield/docker/crontab

WORKDIR /bug-yield
