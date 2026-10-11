FROM php:8.4-apache

# Dépendances système, Python, Pip et extensions PHP requises (dont gd)
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

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Apache : racine sur public/, réécriture d'URL, .htaccess autorisé
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -s 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -s 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo 'ServerName localhost' >> /etc/apache2/apache2.conf \
    && a2enmod rewrite

WORKDIR /var/www/html

# Copie du projet
COPY . .

# Dépendances Composer (sans scripts dev) et Python (FastF1)
RUN composer install --no-dev --optimize-autoloader --no-scripts
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt --break-system-packages; fi

# Cache Symfony prod (secret factice utilisé seulement pendant le build)
ENV APP_ENV=prod
RUN APP_SECRET=build-only-secret php bin/console cache:clear --no-warmup

# Dossiers inscriptibles par Apache (cache, logs, JSON de données)
RUN mkdir -p var/cache/prod var/log data/saisons \
    && chown -R www-data:www-data var data public \
    && chmod -R ug+rwX var data

EXPOSE 80
