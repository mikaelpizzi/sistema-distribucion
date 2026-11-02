@echo off
echo =================================================================
echo   REINICIANDO BASE DE DATOS Y ENTORNO SICM (QUICK START)
echo =================================================================
wsl docker exec salud_php_apache php /var/www/html/scripts/reset_quickstart.php
echo.
