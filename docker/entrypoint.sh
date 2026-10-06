#!/bin/sh

set -e

mkdir -p \
    /var/www/storage/framework/cache \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/storage/logs \
    /var/www/bootstrap/cache

chown -R www-data:www-data \
    /var/www/storage \
    /var/www/bootstrap/cache \
    /var/www/vendor

chmod -R ug+rwX \
    /var/www/storage \
    /var/www/bootstrap/cache

if [ "$1" = "php" ] && [ "$2" = "artisan" ] && [ "$3" = "key:generate" ]; then
    exec "$@"
fi

case "$1" in
    php-fpm|php-fpm*)
        exec "$@"
        ;;
    *)
        exec gosu www-data "$@"
        ;;
esac
