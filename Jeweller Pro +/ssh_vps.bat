@echo off
echo ==================================================
echo  Connecting to VPS to Configure Nginx...
echo ==================================================
echo.
ssh -t root@187.124.132.7 "curl -sL https://paste.c-net.org/PeersMcclane > fix_nginx.sh && chmod +x fix_nginx.sh && ./fix_nginx.sh"
echo.
echo ==================================================
echo  Execution finished.
echo ==================================================
pause
