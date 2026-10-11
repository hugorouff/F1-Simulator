FROM php:8.2-fpm-alpine

# Installation des dépendances système (Nginx, Python, Pip)
RUN apk add --no-cache \
    nginx \
    python3 \
    py3-pip \
    icu-dev \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    bash

# Installation des extensions PHP requis par Symfony
RUN docker-php-ext-install pdo pdo_pgsql intl zip opcache

# Installation de Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copie des fichiers du projet
COPY . .

# Installation des dépendances Symfony & Python
RUN composer install --no-dev --optimize-autoloader
RUN if [ -f requirements.txt ]; then pip install --no-cache-dir -r requirements.txt --break-system-packages; fi

# Configuration Nginx
RUN mkdir -p /run/nginx
RUN echo 'server { \
    listen 8080; \
    root /var/www/html/public; \
    index index.php; \
    location / { \
        try_files $uri /index.php$is_args$args; \
    } \
    location ~ ^/index\.php(/|$) { \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_split_path_info ^(.+\.php)(/.*)$; \
        include fastcgi_params; \
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name; \
        fastcgi_param DOCUMENT_ROOT $realpath_root; \
        internal; \
    } \
    location ~ \.php$ { \
        return 404; \
    } \
}' > /etc/nginx/http.d/default.conf

EXPOSE 8080

CMD php-fpm -D && nginx -g 'daemon off;'
