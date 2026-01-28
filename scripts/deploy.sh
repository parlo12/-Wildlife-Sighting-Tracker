#!/bin/bash

# Quick Deployment Script
# Run this to manually deploy updates

set -e

echo "🚀 Deploying Wildlife Sighting Tracker..."

cd /var/www/wildlife-tracker

# Pull latest code
echo "📥 Pulling latest code from GitHub..."
git pull origin main

# Update permissions
echo "🔒 Setting permissions..."
chmod 755 /var/www/wildlife-tracker
mkdir -p uploads logs
chmod -R 755 uploads logs
chown -R www-data:www-data uploads logs

# Restart services
echo "🔄 Restarting services..."
docker-compose restart php-fpm || echo "⚠️  PHP-FPM restart failed (may not be running)"

# Clear PHP opcache if available
docker-compose exec -T php-fpm php -r "if (function_exists('opcache_reset')) { opcache_reset(); echo 'OPcache cleared'; }" || true

echo ""
echo "✅ Deployment complete!"
echo "🌐 Visit https://koteglasye.com to see your changes"
