#!/bin/bash
cd /var/www/html
chown -R www-data:www-data .
chmod -R 755 .
mysql -u root radhe_shyam_jewellers < setup_database.sql
systemctl restart nginx
echo DONE
