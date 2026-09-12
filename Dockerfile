# ---------------------------------------------------------------------------
# Imagem da aplicação (php-fpm). O nginx roda como sidecar no pod (k8s),
# compartilhando o código via volume; por isso esta imagem expõe só o fpm.
# ---------------------------------------------------------------------------

# --- Estágio 1: build dos assets (Vite) ---
FROM node:24-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

# --- Estágio 2: runtime php-fpm ---
FROM php:8.4-fpm-bookworm

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production \
    APP_DEBUG=false

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev libonig-dev libxml2-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install bcmath intl mbstring pcntl pdo_pgsql pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Configs de php-fpm/opcache tunadas para produção.
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

COPY --from=assets /app/public/build ./public/build

# Swagger/OpenAPI gerado no build: storage/ é efêmero por pod, e o
# l5-swagger roda com generate_always=false. Sem isto, cada pod serviria a
# documentação que existisse na sua própria camada de storage (ou nenhuma),
# e um restart bastaria para voltar a divergir do código.
# A geração só lê as anotações — não precisa de banco. O APP_KEY abaixo é
# descartável, usado apenas para o artisan bootar durante o build.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan l5-swagger:generate \
    && chown -R www-data:www-data storage/api-docs

EXPOSE 9000

CMD ["php-fpm", "--nodaemonize"]
