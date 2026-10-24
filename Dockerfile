# Image de départ : PHP 8.4 en mode FPM, sur Alpine Linux (légère). Version exacte : les correctifs
# de sécurité de PHP arrivent dans l'image en changeant ce numéro (8.4.27 : correctifs du 21/10/2026).
FROM php:8.4.27-fpm-alpine AS php

# Extensions PHP dont Symfony et LogeTogo ont besoin.
# ffmpeg : convertit l'audio des téléphones (M4A) en WAV pour Whisper (annonce vocale).
# gd : exigé par PhpSpreadsheet (exports Excel de l'administration).
RUN apk add --no-cache icu-dev libzip-dev ffmpeg libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql intl zip opcache gd

# Composer : on récupère seulement son programme dans l'image officielle de Composer.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dossier de travail dans le conteneur : toutes les commandes suivantes s'y exécutent.
WORKDIR /var/www/html

# Mode production de Symfony.
ENV APP_ENV=prod

# 1) D'abord les fichiers de dépendances seuls, puis l'installation des bibliothèques.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader

# 2) Ensuite tout le reste du code.
COPY . .
RUN composer dump-autoload --no-dev --optimize

# Réglages PHP de production (le préfixe « zz- » le fait lire en dernier, donc il l'emporte).
COPY docker/php/logetogo.ini /usr/local/etc/php/conf.d/zz-logetogo.ini

# Fichiers de l'administration (CSS, JS) assemblés une fois pour toutes dans public/assets.
RUN php bin/console importmap:install \
    && php bin/console asset-map:compile

# Dossiers où l'application écrit, confiés à l'utilisateur www-data.
RUN mkdir -p var/verifications public/uploads config/jwt \
    && chown -R www-data:www-data var public/uploads

# Par sécurité, PHP ne tourne pas en « root » mais avec l'utilisateur limité www-data.
USER www-data

# PHP-FPM attend les requêtes de Nginx sur le port 9000.
EXPOSE 9000
CMD ["php-fpm"]

# ─── Image Nginx : reçoit les visiteurs, sert les fichiers publics, transmet le PHP ───
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
# Les fichiers publics (index.php, assets compilés) sont pris dans l'étape « php ».
COPY --from=php /var/www/html/public /var/www/html/public

# ─── Whisper (voix → texte) : compilé à partir du code source officiel de whisper.cpp ───
# Étape 1 : la compilation (outils lourds, absents de l'image finale).
FROM debian:bookworm-slim AS whisper_compilation
ARG WHISPER_VERSION=v1.9.2
RUN apt-get update && apt-get install -y --no-install-recommends git cmake build-essential ca-certificates \
    && git clone --depth 1 --branch ${WHISPER_VERSION} https://github.com/ggml-org/whisper.cpp /src \
    && cmake -S /src -B /src/build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DGGML_NATIVE=OFF \
    && cmake --build /src/build --config Release -j"$(nproc)" --target whisper-server

# Étape 2 : l'image finale, avec seulement le programme compilé.
FROM debian:bookworm-slim AS whisper
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates libgomp1 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=whisper_compilation /src/build/bin/whisper-server /usr/local/bin/whisper-server
COPY docker/whisper/demarrer.sh /usr/local/bin/demarrer-whisper
RUN chmod +x /usr/local/bin/demarrer-whisper
EXPOSE 8080
CMD ["demarrer-whisper"]
