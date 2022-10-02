FROM composer:2.0 as step0

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer update --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist
    
FROM php:8.0-cli-alpine as final

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
  git clone https://github.com/php-memcached-dev/php-memcached.git /usr/src/php/ext/memcached && \
  cd /usr/src/php/ext/memcached && \
  git checkout tags/v3.2.0 && \
  docker-php-ext-configure memcached && \
  docker-php-ext-install memcached && \
  # # cleanup 
  docker-php-source delete 
  
WORKDIR /usr/src/code

# Add Source Code
COPY ./ /usr/src/code

COPY --from=step0 /usr/local/src/vendor /usr/src/code/vendor

CMD [ "tail", "-f", "/dev/null" ]
