#!/bin/sh
set -e

echo "🚀 Starting application..."

if [ -f /var/www/html/artisan ]; then
  mkdir -p /var/www/html/storage/app/public
  php /var/www/html/artisan storage:link --force >/dev/null 2>&1 || true
fi

exec "$@"
