FROM php:8.4-apache

# Installation des dépendances système, Python, Pip et extensions PHP requises (dont gd)
RUN apt-get update && apt-get install -y \
    python3 \
    python3-pip \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql intl zip gd

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configuration d'Apache pour pointer vers le dossier public de Symfony
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -s 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -s 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copie des fichiers du projet
COPY . .

# Installation des dépendances Composer (sans scripts dev) et Python (FastF1)
RUN composer install --no-dev --optimize-autoloader --no-scripts
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt --break-system-packages; fi

# Vidage du cache Symfony en prod (exécuté en root)
ENV APP_ENV=prod
ENV APP_SECRET=123456789abcdef
RUN php bin/console cache:clear --no-warmup

# RE-DONNER LES DROITS À WWW-DATA APRÈS LE BUILD (Indispensable !)
RUN mkdir -p /var/www/html/var/cache/prod /var/www/html/var/log \
    && chmod -R 777 /var/www/html/var \
    && chown -R www-data:www-data /var/www/html/var /var/www/html/public

EXPOSE 80
