FROM node:24-bookworm-slim@sha256:d6aa754f16b3197301076f047b5def2f02ea1dbbc2ca920407d46d7ec7f87b20 AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN npm run build

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.4-fpm-bookworm@sha256:6bfef8e416977aa41f48e3e42a40c1e08050d24e4a938c6edb421400bff24601 AS runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates unzip libicu-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j4 pdo_mysql mbstring intl zip pcntl bcmath opcache gd \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/production/php.ini /usr/local/etc/php/conf.d/99-smh-production.ini
WORKDIR /var/www/html

FROM runtime AS dependencies
COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && for kind in departments positions categories users stores; do test -r "docs/templates/master-$kind.xlsx"; done \
    && test -r docs/templates/objective-exam-template.xlsx \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts \
    && composer check-platform-reqs --no-dev

FROM runtime AS app
ENV APP_ENV=production APP_DEBUG=false
LABEL org.opencontainers.image.title="SMH Learning" org.opencontainers.image.version="1.0.0"
COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=frontend --chown=www-data:www-data /build/public/build /var/www/html/public/build
COPY --chmod=755 docker/production/entrypoint.sh /usr/local/bin/smh-entrypoint
RUN mkdir -p storage/app/private/learning storage/app/private/reports storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache \
    && chmod 755 storage storage/app storage/app/private storage/app/private/learning \
    && php artisan package:discover --ansi
USER www-data
ENTRYPOINT ["smh-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:stable-alpine@sha256:0985e772fb9f729e6fa0980da05fca5d9c468e870eed43071545afa9d2e27d94 AS web
# Flysystem private uploads belong to PHP UID 33. Keep private permissions and
# run the authorized internal media reader with that same dedicated UID.
RUN addgroup -S -g 33 smh-web \
    && adduser -S -D -H -u 33 -G smh-web smh-web \
    && sed -i 's/^user  nginx;/user  smh-web;/' /etc/nginx/nginx.conf \
    && chown -R smh-web:smh-web /var/cache/nginx
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/production/nginx.conf /etc/nginx/conf.d/default.conf
LABEL org.opencontainers.image.title="SMH Learning web" org.opencontainers.image.version="1.0.0"
