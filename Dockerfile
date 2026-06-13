FROM php:8.4-apache

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-spa \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql gd zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite headers

COPY . .
COPY docker/php-upload-limits.ini /usr/local/etc/php/conf.d/zz-upload-limits.ini

RUN mkdir -p bootstrap/cache \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 bootstrap/cache storage

RUN composer install --no-dev --optimize-autoloader

COPY docker/start-apache.sh /usr/local/bin/start-apache

RUN chmod +x /usr/local/bin/start-apache

EXPOSE 10000

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT:-10000}/up" || exit 1

CMD ["start-apache"]
