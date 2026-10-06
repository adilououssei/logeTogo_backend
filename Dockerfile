# syntax=docker/dockerfile:1
#
# Image de production de l'API LogeTogo (Symfony 8, PHP 8.4), sur le modèle du projet Zonal.
# Trois cibles :
#   - backend : PHP-FPM + l'application (+ ffmpeg pour l'annonce vocale) ;
#   - web     : Nginx avec les fichiers publics (index.php, assets compilés de l'administration) ;
#   - whisper : serveur de transcription vocale (whisper.cpp).
# Utilisé par docker-compose.prod.yml (voir DEPLOIEMENT.md).

# --- Composer (binaire seul) ---
FROM composer:2 AS composer_bin

# --- Application PHP ---
FROM php:8.4-fpm-alpine AS backend

# pdo_mysql (MySQL), intl (symfony/intl, translitération des noms de quartiers), zip (Composer),
# opcache (performances) ; ffmpeg : conversion de l'audio des téléphones (M4A) en WAV pour Whisper.
RUN apk add --no-cache icu-dev libzip-dev ffmpeg \
    && docker-php-ext-install pdo_mysql intl zip opcache

RUN { \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

COPY docker/php/logetogo.ini /usr/local/etc/php/conf.d/zz-logetogo.ini
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1

# Dépendances d'abord : cette couche reste en cache tant que composer.json/lock ne changent pas.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader

COPY . .

# Assets de l'administration (AssetMapper) compilés dans public/assets, servis par Nginx.
RUN composer dump-autoload --no-dev --optimize \
    && php bin/console importmap:install \
    && php bin/console asset-map:compile \
    && mkdir -p var/verifications public/uploads config/jwt \
    && chown -R www-data:www-data var public/uploads config/jwt \
    && chmod -R 775 var public/uploads

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# --- Nginx : fichiers publics de l'application (les envois des utilisateurs sont montés à part) ---
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=backend /var/www/html/public /var/www/html/public

# --- Whisper : voix → texte (whisper.cpp, compilé depuis la version publiée) ---
FROM debian:bookworm-slim AS whisper_build
ARG WHISPER_VERSION=v1.9.2
RUN apt-get update && apt-get install -y --no-install-recommends git cmake build-essential ca-certificates \
    && git clone --depth 1 --branch ${WHISPER_VERSION} https://github.com/ggml-org/whisper.cpp /src \
    && cmake -S /src -B /src/build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DWHISPER_BUILD_TESTS=OFF \
    && cmake --build /src/build --config Release -j"$(nproc)" --target whisper-server

FROM debian:bookworm-slim AS whisper
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates libgomp1 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=whisper_build /src/build/bin/whisper-server /usr/local/bin/whisper-server
COPY docker/whisper/demarrer.sh /usr/local/bin/demarrer-whisper
RUN chmod +x /usr/local/bin/demarrer-whisper
EXPOSE 8080
CMD ["demarrer-whisper"]
