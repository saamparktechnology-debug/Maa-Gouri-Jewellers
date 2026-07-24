#!/bin/bash
# Update Nginx config
CONF="/etc/nginx/sites-enabled/radheyshyamjewellers"
if [ -f "$CONF" ]; then
    # Replace root directory with quotes
    sed -i 's|root /var/www/Radhe-Shyam-Jewellers;|root "/var/www/Radhe-Shyam-Jewellers/Web-Jewellery Pro+_1";|g' "$CONF"
    # Replace PHP version socket
    sed -i 's|php8.5-fpm.sock|php8.2-fpm.sock|g' "$CONF"
    
    # Test Nginx and restart
    nginx -t
    if [ $? -eq 0 ]; then
        systemctl restart nginx
        echo "✅ Nginx configured and restarted successfully!"
    else
        echo "❌ Nginx configuration test failed!"
    fi
else
    echo "❌ Config file not found at $CONF"
fi
