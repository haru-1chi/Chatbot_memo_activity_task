#!/usr/bin/env bash

# Run composer
echo "Running composer"
composer dump-autoload
composer install --no-dev --working-dir=/var/www/html

# Set permissions
echo "Setting permissions..."
chmod -R 777 /var/www/html/storage/logs

# Clear optimization
echo "Clearing optimization..."
php artisan optimize:clear

# Cache configuration
echo "Caching configuration..."
php artisan config:cache

# Cache routes
echo "Caching routes..."
php artisan route:cache

# Run migrations
echo "Running migrations..."
php artisan migrate --force

echo "Setting up cron job for Laravel scheduler..."
# php artisan schedule:work
chmod 755 /etc/cron.d/
cd /var/www/html && php artisan schedule:run >> /var/www/html/cron.log 2>&1
echo "Deployment complete."

# echo "Setting up cron job for Laravel scheduler..."
# php artisan schedule:work

#echo "Running seeders..."
#php artisan db:seed

#echo "Running vite..."
#npm install
#npm run build
