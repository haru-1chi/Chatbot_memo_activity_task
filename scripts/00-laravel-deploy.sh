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

#echo "Running seeders..."
#php artisan db:seed

#echo "Running vite..."
#npm install
#npm run build
