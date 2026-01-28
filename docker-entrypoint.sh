#!/bin/sh
set -e

# Create directories if they don't exist and set permissions
mkdir -p /var/www/wildlife-tracker/uploads /var/www/wildlife-tracker/logs
chmod -R 777 /var/www/wildlife-tracker/uploads /var/www/wildlife-tracker/logs

# Start PHP-FPM
exec php-fpm
