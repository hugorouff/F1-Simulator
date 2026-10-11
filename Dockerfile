FROM php:8.4-apache

# Dépendances système, Python, pip et extensions PHP (dont gd pour PhpSpreadsheet)
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
    && docker-php-ext-install pdo pdo_mysql intl zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Apache : racine sur public/, réécriture d'URL, .htaccess autorisé
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY . .

ENV APP_ENV=prod

# Dépendances PHP (sans scripts : le MakerBundle est un paquet de dev) et Python
RUN composer install --no-dev --optimize-autoloader --no-scripts
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt --break-system-packages; fi

# Cache Symfony (APP_SECRET réel à définir dans Render > Environment)
RUN APP_SECRET=build php bin/console cache:clear --no-warmup

# Dossiers inscriptibles par Apache (cache, logs, JSON de données) : à faire en dernier
RUN mkdir -p var/cache var/log data/saisons \
    && chown -R www-data:www-data var data \
    && chmod -R 775 var data

EXPOSE 80
