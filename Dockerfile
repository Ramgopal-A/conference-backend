FROM php:8.2-cli

# Install PostgreSQL extensions for PHP
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

WORKDIR /var/www/html

COPY . .

# Render assigns a dynamic port via the PORT environment variable
EXPOSE 10000

# Start PHP built-in server bound to Render's required host and port
CMD ["php", "-S", "0.0.0.0:10000", "index.php"]