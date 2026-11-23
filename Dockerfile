# Signaculum multi-stage image.
#   docker build --target production -t signaculum:prod .
#   docker buildx bake -f docker-bake.hcl production

FROM php:8.6-fpm-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl git unzip \
        libicu-dev libldap2-dev libpq-dev libsqlite3-dev libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure ldap --with-libdir=lib/$(uname -m)-linux-gnu \
    && docker-php-ext-install -j$(nproc) bcmath intl ldap opcache pcntl pdo_mysql pdo_pgsql pdo_sqlite zip \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

FROM php-base AS build

COPY --from=node:26-bookworm /usr/local/bin/node /usr/local/bin/node
COPY --from=node:26-bookworm /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p database \
    && touch database/database.sqlite \
    && php -r "file_put_contents('.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32)).PHP_EOL);" \
    && php artisan package:discover --ansi \
    && npm run build \
    && rm -rf node_modules .env

FROM php-base AS production

RUN apt-get update && apt-get install -y --no-install-recommends nginx curl \
    && rm -rf /var/lib/apt/lists/* \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/php/opcache.prod.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-signaculum.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default \
    && mkdir -p /run/nginx

COPY --from=build /var/www/html /var/www/html

RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["app"]
