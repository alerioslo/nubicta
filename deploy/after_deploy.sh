#!/usr/bin/env bash
# Se ejecuta en el servidor despues de cada push a main.
set -e
export COMPOSER_ALLOW_SUPERUSER=1
cd /var/www/erp
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader -q
npm ci --silent
npm run build --silent
php artisan migrate --force
php artisan storage:link -q 2>/dev/null || true
php artisan optimize:clear -q
php artisan optimize -q
chown -R www-data:www-data storage bootstrap/cache
supervisorctl restart erp-horizon >/dev/null 2>&1 || true
systemctl reload php8.3-fpm
echo "DEPLOY_OK $(git rev-parse --short HEAD)"
