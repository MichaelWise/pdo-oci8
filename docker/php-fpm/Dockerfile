FROM registry.is74.ru/docker/php:8.1.3RC1-fpm-buster

RUN apt-get update \
    && apt-get install -y \
        librabbitmq-dev \
        libssl1.1 \
        libssh-dev \
    && pecl install amqp && docker-php-ext-enable amqp \
     && pecl channel-update pecl.php.net
