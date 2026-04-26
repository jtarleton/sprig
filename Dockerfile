FROM php:8.3-cli-alpine

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        pkgconf \
        sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && apk del .build-deps \
    && apk add --no-cache sqlite-libs

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www
COPY . .
RUN mkdir -p data && composer install --no-dev --no-interaction --prefer-dist

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "docroot"]
