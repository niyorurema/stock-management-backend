# On utilise une image PHP récente avec FPM
FROM php:8.2-fpm-alpine

# Installation des dépendances système pour intl (ICU) et zip
RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        zip \
        unzip

# Installation des extensions PHP nécessaires pour CodeIgniter 4
# Note : intl doit être installé APRÈS icu-dev
RUN docker-php-ext-configure intl && \
    docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli \
        intl \
        zip

# On installe Composer (gestionnaire de dépendances PHP)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# On définit le dossier de travail
WORKDIR /var/www/html

# Copie de notre code source dans le conteneur
COPY . .

# Installation des dépendances PHP (via composer.json)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# On ajuste les droits pour que le serveur web puisse écrire dans les logs et le cache
RUN chown -R www-data:www-data /var/www/html/writable
RUN chmod -R 755 /var/www/html/writable

# On expose le port 8080 (que Render utilisera par défaut)
EXPOSE 8080

# On démarre le serveur PHP intégré, en écoutant sur toutes les interfaces.
#CMD php spark serve --host=0.0.0.0 --port=8080

# On expose le port 8080
EXPOSE 8080

# On démarre le serveur PHP sur toutes les interfaces
CMD php -S 0.0.0.0:8080 -t public