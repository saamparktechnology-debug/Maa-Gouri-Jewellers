#!/bin/bash
echo "=================================================="
echo " RADHE SHYAM JEWELLERS - VPS Auto Update"
echo "=================================================="

git config --global --add safe.directory "*" 2>/dev/null
git fetch --all
git reset --hard origin/main
chown -R www-data:www-data . 2>/dev/null || true
chmod -R 755 . 2>/dev/null || true
systemctl restart php8.2-fpm 2>/dev/null || systemctl restart php8.1-fpm 2>/dev/null || systemctl restart php8.0-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
systemctl reload nginx 2>/dev/null || systemctl reload apache2 2>/dev/null || true

echo "=================================================="
echo " ✅ Live Website Successfully Updated!"
echo "=================================================="
