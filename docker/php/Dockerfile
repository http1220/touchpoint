FROM php:8.2-fpm-alpine

# CodeIgniter 3 를 PHP 8.2에서 돌린다.
#
# CI3의 공식 지원 PHP는 7.4다. 8.2에서는 deprecated 경고와 소소한 비호환이 나온다.
# 그걸 피하지 않고 고쳐 나가는 과정 자체가 이 프로젝트가 만들려는 경험이다.
#   - PHP 7.4는 이미 EOL, 8.1도 2025-12에 종료됐다
#   - 공개 서버에 EOL 런타임을 올릴 수는 없다
# 부딪힌 것은 docs/worklog.md 에 기록한다. → docs/decisions/ADR-001

RUN apk add --no-cache --virtual .build icu-dev oniguruma-dev $PHPIZE_DEPS \
 && apk add --no-cache icu-libs \
 # CI3는 mysqli 드라이버를 기본으로 쓴다. pdo_mysql은 병행 비교용.
 && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql intl opcache bcmath \
 && apk del .build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=2'; \
      echo 'opcache.memory_consumption=64'; \
      echo 'memory_limit=128M'; \
      echo 'expose_php=Off'; \
      echo 'date.timezone=UTC'; \
      # CI3 + PHP 8.2 의 deprecated 경고를 로그로 남긴다. 숨기지 않는다.
      echo 'error_reporting=E_ALL'; \
      echo 'display_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

RUN { \
      echo '[www]'; \
      echo 'pm = dynamic'; \
      echo 'pm.max_children = 8'; \
      echo 'pm.start_servers = 2'; \
      echo 'pm.min_spare_servers = 1'; \
      echo 'pm.max_spare_servers = 4'; \
      echo 'clear_env = no'; \
      echo 'catch_workers_output = yes'; \
    } > /usr/local/etc/php-fpm.d/zz-pool.conf

EXPOSE 9000
CMD ["php-fpm"]
