FROM php:8.3-fpm-alpine

# CI4 요구 확장 + 이 프로젝트에 필요한 것
#   pdo_mysql  DB
#   intl       ICU. CI4가 요구
#   opcache    성능
#   bcmath     금액을 정수 minor unit으로 다루지만 나눗셈 검증용
RUN apk add --no-cache --virtual .build icu-dev oniguruma-dev $PHPIZE_DEPS \
 && apk add --no-cache icu-libs \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql intl opcache bcmath \
 && apk del .build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# opcache — 개발 중에는 파일 변경을 즉시 반영해야 하므로 validate_timestamps를 켠다.
# 운영 전환 시 0으로 바꾸고 배포마다 재시작한다.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
      echo 'opcache.memory_consumption=64'; \
      echo 'memory_limit=128M'; \
      echo 'expose_php=Off'; \
      echo 'date.timezone=UTC'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# php-fpm 기본 pool은 pm=dynamic, max_children=5.
# t3.micro에서는 이 정도가 적당하다. 부하 테스트 후 benchmarks.md에 기록한다.
RUN { \
      echo '[www]'; \
      echo 'pm = dynamic'; \
      echo 'pm.max_children = 5'; \
      echo 'pm.start_servers = 2'; \
      echo 'pm.min_spare_servers = 1'; \
      echo 'pm.max_spare_servers = 3'; \
      echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-pool.conf

EXPOSE 9000
CMD ["php-fpm"]
