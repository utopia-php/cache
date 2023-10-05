FROM composer:2.0 as step0

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist
    
FROM php:8.0-cli-alpine as final

ENV PHP_MEMCACHED_VERSION=v3.2.0

LABEL maintainer="team@appwrite.io"

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN \
  apk update \
  && apk add --no-cache make automake autoconf gcc g++ git zlib-dev libmemcached-dev \
  && rm -rf /var/cache/apk/*

RUN \
  # Redis Extension
  git clone https://github.com/phpredis/phpredis.git && \
  cd phpredis && \
  git checkout $PHP_REDIS_VERSION && \
  phpize && \
  ./configure && \
  make && make install && \
  cd ..

RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini


RUN \
  # Memcached Extension
  git clone --branch $PHP_MEMCACHED_VERSION https://github.com/php-memcached-dev/php-memcached.git && \
  cd php-memcached && \
  phpize && \
  ./configure && \
  make && make install && \
  cd ..

RUN echo extension=memcached.so >> /usr/local/etc/php/conf.d/memcached.ini

# Install Mcrouter Extension
RUN \
  # Mcrouter Extension
  git clone https://github.com/facebook/mcrouter.git && \
  cd mcrouter/mcrouter && \
  phpize && \
  ./configure && \
  make && make install && \
  cd ..

# Add the Mcrouter extension to the PHP configuration
RUN echo extension=mcrouter.so >> /usr/local/etc/php/conf.d/mcrouter.ini

WORKDIR /usr/src/code

# Add Source Code
COPY ./ /usr/src/code

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor

CMD [ "tail", "-f", "/dev/null" ]
