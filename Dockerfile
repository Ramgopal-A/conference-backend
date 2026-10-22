FROM php:8.2-cli

WORKDIR /var/www/html

# Install system dependencies, PostgreSQL extensions, curl, and Composer
RUN apt-get update && apt-get install -y libpq-dev git unzip curl \
    && docker-php-ext-install pdo pdo_pgsql gd \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy project files
COPY . /var/www/html

# Install PHP dependencies via Composer
RUN composer install --no-dev --optimize-autoloader

# Render assigns a dynamic port via $PORT
EXPOSE 10000

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-10000} index.php"]