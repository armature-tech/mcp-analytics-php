FROM caddy:2.11.4-alpine AS caddy

FROM php:8.3-fpm-alpine3.24

COPY --from=composer:2.10.1 /usr/bin/composer /usr/local/bin/composer
COPY --from=caddy /usr/bin/caddy /usr/bin/caddy

RUN apk add --no-cache libzip-dev \
    && docker-php-ext-install opcache zip \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -rf /tmp/*

WORKDIR /app

COPY candidate.zip /tmp/repository/candidate.zip
RUN composer init --no-interaction --name=armature/php-sdk-canary \
    && composer config repositories.candidate artifact /tmp/repository \
    && composer require \
        armature/mcp-analytics:0.1.0 \
        nyholm/psr7:^1.8 \
        nyholm/psr7-server:^1.1 \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
    && composer clear-cache \
    && rm -rf /tmp/repository

COPY public /app/public
COPY Caddyfile /etc/caddy/Caddyfile

RUN mkdir -p /app/sessions \
    && chmod -R a+rX /app \
    && chown -R www-data:www-data /app/sessions /app/public

EXPOSE 8080

HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/healthz || exit 1

CMD ["sh", "-ec", "php-fpm -D && exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile"]
