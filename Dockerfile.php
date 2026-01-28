FROM php:8.2-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql exif

# Set working directory
WORKDIR /var/www/wildlife-tracker

# Expose PHP-FPM port
EXPOSE 9000

CMD ["php-fpm"]
