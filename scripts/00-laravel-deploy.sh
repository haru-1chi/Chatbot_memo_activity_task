#!/usr/bin/env bash
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

# Set up cron job for Laravel scheduler
echo "Setting up cron job for Laravel scheduler..."
echo "* * * * * cd /var/www/html && php artisan schedule:run >> /var/www/html/cron.log 2>&1" > /var/www/html/cron-job

# Install the cron job
sudo mv /var/www/html/cron-job /etc/cron.d/laravel-scheduler

echo "Deployment complete."

#echo "Running seeders..."
#php artisan db:seed

#echo "Running vite..."
#npm install
#npm run build
