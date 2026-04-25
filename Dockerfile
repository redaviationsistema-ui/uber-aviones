FROM php:8.4-cli

WORKDIR /var/www

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    curl

RUN docker-php-ext-install pdo pdo_mysql pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

# Crear carpetas necesarias
RUN mkdir -p bootstrap/cache storage/framework storage/logs

# Permisos
RUN chmod -R 777 bootstrap/cache storage

# Instalar dependencias
RUN composer install --no-dev --optimize-autoloader

EXPOSE 10000

CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
