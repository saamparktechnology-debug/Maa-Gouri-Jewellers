#!/bin/bash
echo "=================================================="
echo " RADHE SHYAM JEWELLERS - VPS Auto Setup"
echo "=================================================="

echo "[1/4] Setting file permissions..."
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html

echo "[2/4] Creating MySQL database..."
mysql -u root -e "CREATE DATABASE IF NOT EXISTS radhe_shyam_jewellers CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || \
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS radhe_shyam_jewellers CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "[3/4] Importing database schema & admin users..."
mysql -u root radhe_shyam_jewellers < /var/www/html/setup_database.sql 2>/dev/null || \
mysql -u root -p radhe_shyam_jewellers < /var/www/html/setup_database.sql

echo "[4/4] Restarting web server..."
systemctl restart nginx 2>/dev/null || systemctl restart apache2 2>/dev/null || true
systemctl restart php8.2-fpm 2>/dev/null || systemctl restart php8.1-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true

echo "=================================================="
echo " SUCCESS! Auto Setup Completed."
echo " Admin Logins:"
echo " 1) subhapatra169@gmail.com / radhe#123"
echo " 2) hiisupriya@gmail.com / 123456"
echo "=================================================="
