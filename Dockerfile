FROM php:8.4-apache

# Installation des packages système et extensions PHP
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

# Configuration des limites mémoire et MPM Apache
RUN echo "memory_limit = 256M" > /usr/local/etc/php/conf.d/memory-limit.ini
RUN echo "<IfModule mpm_prefork_module>\n\
    StartServers 1\n\
    MinSpareServers 1\n\
    MaxSpareServers 2\n\
    MaxRequestWorkers 4\n\
    MaxConnectionsPerChild 100\n\
</IfModule>" >> /etc/apache2/apache2.conf

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configuration d'Apache DocumentRoot et activation du rewrite .htaccess
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -s 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -s 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf
RUN a2enmod rewrite

# PERMETTRE LE REWRITE DANS PUBLIC (Règle le problème de 404 sur /replay et /simulator)
RUN echo "<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Copie du projet
COPY . .

# Installation dépendances
RUN composer install --no-dev --optimize-autoloader --no-scripts
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt --break-system-packages; fi

# Configuration prod & redirection du cache vers /tmp
ENV APP_ENV=prod
ENV APP_SECRET=123456789abcdef0123456789abcdef0
ENV SYMFONY_CACHE_DIR=/tmp/symfony_cache
ENV SYMFONY_LOG_DIR=/tmp/symfony_logs

RUN php bin/console cache:clear --no-warmup

# Création des dossiers temporaires et permissions
RUN mkdir -p /tmp/symfony_cache /tmp/symfony_logs /tmp/fastf1_cache \
    && chmod -R 777 /tmp/symfony_cache /tmp/symfony_logs /tmp/fastf1_cache \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
