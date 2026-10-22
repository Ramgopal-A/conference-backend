# Stage 1: Get the official composer binary
FROM composer:latest AS composer

# Stage 2: Production PHP environment
FROM php:8.2-cli

WORKDIR /var/www/html

# Install system dependencies, PostgreSQL extensions, and zip support
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev \
    git \
    unzip \
    libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

# Copy the official composer binary from Stage 1
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy project files
COPY . /var/www/html

# Install PHP dependencies via Composer
RUN composer install --no-dev --optimize-autoloader

# Render assigns a dynamic port via $PORT
EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} index.php"]