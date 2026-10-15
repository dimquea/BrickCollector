# syntax=docker/dockerfile:1
#
# BrickCollector как обычный контейнер — для своей машины или домашнего
# сервера, без Home Assistant.
#
# Собирается из рабочего дерева, а не из тега на GitHub: этим он и отличается
# от образа аддона, который берёт выпущенную версию и ничего не знает о
# локальных правках.
#
# Всё изменяемое — база, кэш картинок, архив справочника, ключ приложения —
# живёт в /data: подключите туда том, и контейнер можно пересоздавать сколько
# угодно.

# ── Сборка: composer и vite, чтобы их инструменты не попали в образ ─────────
FROM alpine:3.21 AS build

RUN apk add --no-cache \
        curl \
        nodejs \
        npm \
        php84 \
        php84-ctype \
        php84-curl \
        php84-dom \
        php84-fileinfo \
        php84-iconv \
        php84-mbstring \
        php84-openssl \
        php84-pdo \
        php84-pdo_sqlite \
        php84-phar \
        php84-session \
        php84-simplexml \
        php84-tokenizer \
        php84-xml \
        php84-xmlreader \
        php84-xmlwriter \
        php84-zip \
    && ln -sf /usr/bin/php84 /usr/bin/php \
    # Composer готовым phar, а не пакетом Alpine: пакет тянет за собой
    # собственный PHP и перехватывает /usr/bin/php.
    && curl -sSLo /usr/local/bin/composer \
        https://getcomposer.org/download/latest-stable/composer.phar \
    && chmod a+x /usr/local/bin/composer

WORKDIR /app

# Зависимости отдельным слоем: правка в коде не должна заставлять composer и
# npm ходить в сеть заново.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && npm run build \
    && rm -rf node_modules tests .git

# ── Образ ──────────────────────────────────────────────────────────────────
FROM alpine:3.21

RUN apk add --no-cache \
        curl \
        nginx \
        php84 \
        php84-fpm \
        php84-ctype \
        php84-curl \
        php84-dom \
        php84-fileinfo \
        php84-iconv \
        php84-mbstring \
        php84-opcache \
        php84-openssl \
        php84-pdo \
        php84-pdo_sqlite \
        php84-phar \
        php84-session \
        php84-simplexml \
        php84-sqlite3 \
        php84-tokenizer \
        php84-xml \
        php84-xmlreader \
        php84-xmlwriter \
        php84-zip \
    && ln -sf /usr/bin/php84 /usr/bin/php \
    && rm -f /etc/php84/php-fpm.d/www.conf \
        /etc/nginx/http.d/default.conf /etc/nginx/conf.d/default.conf

COPY --from=build /app /app
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /etc/php84/php-fpm.d/brickcollector.conf
COPY docker/php.ini /etc/php84/conf.d/99-brickcollector.ini
COPY docker/entrypoint.sh /usr/local/bin/brickcollector

RUN chmod a+x /usr/local/bin/brickcollector \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /data \
        /app/storage/framework/cache/data /app/storage/framework/sessions \
        /app/storage/framework/views /app/storage/logs /app/bootstrap/cache \
    && chmod -R 0777 /app/storage /app/bootstrap/cache

# Всё, что переживает пересоздание контейнера.
VOLUME ["/data"]

ENV BRICKCOLLECTOR_DATA_PATH=/data \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=file \
    APP_LOCALE=en \
    APP_FALLBACK_LOCALE=en \
    BRICKCOLLECTOR_CURRENCY=USD \
    BRICKCOLLECTOR_PHP_BINARY=/usr/bin/php \
    IMPORT_CATALOG=true \
    FETCH_IMAGES=true \
    FETCH_BATCH=200 \
    FETCH_INTERVAL=300

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=120s \
    CMD curl -fsS http://127.0.0.1:8080/ -o /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/brickcollector"]
