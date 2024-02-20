#!/usr/bin/env bash
echo "Running composer"
composer dump-autoload
composer install --no-dev --working-dir=/var/www/html
chmod -R 777 /var/www/html/storage/logs

echo "Caching optimize..."
php artisan optimize:clear

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache

echo "Running migrations..."
php artisan migrate --force

# Set up cron job for Laravel scheduler
echo "Setting up cron job for Laravel scheduler..."
echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" > /etc/cron.d/laravel-scheduler

#echo "Running seeders..."
#php artisan db:seed

#echo "Running vite..."
#npm install
#npm run build
