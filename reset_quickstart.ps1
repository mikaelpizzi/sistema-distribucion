Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "  REINICIANDO BASE DE DATOS Y ENTORNO SICM (QUICK START) " -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan

try {
    wsl docker exec salud_php_apache php /var/www/html/scripts/reset_quickstart.php
} catch {
    docker exec salud_php_apache php /var/www/html/scripts/reset_quickstart.php
}

