# syntax=docker/dockerfile:1.7

# ---------- Stage 1: PHP deps (full source, so the optimized ----------
# ---------- autoloader is generated correctly — composer isn't --------
# ---------- present in the FrankenPHP runtime image) -------------------
FROM composer:2 AS composer-deps
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --prefer-dist --ignore-platform-reqs --no-interaction

# ---------- Stage 2: frontend build ----------
FROM node:20-alpine AS frontend-build
WORKDIR /app
COPY package*.json ./
RUN npm install --no-audit --no-fund
COPY . .
RUN npm run build

# ---------- Stage 3: runtime (FrankenPHP) ----------
FROM dunglas/frankenphp:php8.2-alpine AS production

RUN install-php-extensions \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    opcache

WORKDIR /app

COPY --from=composer-deps /app ./
COPY --from=frontend-build /app/public/build ./public/build

RUN php artisan storage:link || true \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

ENV SERVER_NAME=:80
ENV APP_ENV=production

EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
