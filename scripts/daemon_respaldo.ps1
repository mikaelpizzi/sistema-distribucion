# ==============================================================================
# SICM - Sistema Integrado de Control de Medicamentos
# scripts/daemon_respaldo.ps1
# Demonio Autónomo de Respaldo Transaccional en PowerShell (Windows)
# ==============================================================================

$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RootDir = Split-Path -Parent $ScriptDir
$BackupDir = Join-Path $RootDir "respaldos"

if (-not (Test-Path $BackupDir)) {
    New-Item -ItemType Directory -Path $BackupDir | Out-Null
}

$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$ContainerName = "salud_maria_db"
$DbName = "control_medicamentos_red"
$DbUser = "app_salud_user"
$DbPass = "app_password_seguro_2026"

$TmpSql = Join-Path $BackupDir "respaldo_$Timestamp.sql.tmp"
$FinalGz = Join-Path $BackupDir "respaldo_sicm_$Timestamp.sql.gz"
$ShaFile = "$FinalGz.sha256"

Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "  SICM | DEMONIO DE RESPALDO TRANSACCIONAL (PowerShell Windows) " -ForegroundColor Cyan
Write-Host "=================================================================" -ForegroundColor Cyan
Write-Host "[*] Fecha y hora: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
Write-Host "[*] Destino: $BackupDir"

# 1. Comprobar que Docker y el contenedor están activos
$running = wsl -e docker ps --format "{{.Names}}" | Select-String -Pattern "^$ContainerName$"
if (-not $running) {
    Write-Error "[ERROR CRÍTICO] El contenedor '$ContainerName' no está en ejecución."
    exit 1
}

# 2. Generar archivo temporal de credenciales seguro en el contenedor
wsl -e docker exec $ContainerName bash -c "cat << 'EOF' > /tmp/.dump_creds.cnf
[client]
user=$DbUser
password=$DbPass
EOF
chmod 600 /tmp/.dump_creds.cnf"

try {
    Write-Host "[*] Ejecutando volcado transaccional InnoDB (--single-transaction)..." -ForegroundColor Yellow

    # 3. Volcado atómico en archivo temporal (.tmp)
    $dumpProc = Start-Process -FilePath "wsl" -ArgumentList "-e", "docker", "exec", $ContainerName, "mariadb-dump", `
        "--defaults-extra-file=/tmp/.dump_creds.cnf", `
        "--single-transaction", `
        "--quick", `
        "--routines", `
        "--triggers", `
        "--events", `
        "--hex-blob", `
        "--default-character-set=utf8mb4", `
        $DbName -RedirectStandardOutput $TmpSql -NoNewWindow -Wait -PassThru

    if ($dumpProc.ExitCode -ne 0) {
        throw "mariadb-dump finalizó con código de error $($dumpProc.ExitCode)"
    }

    $dumpItem = Get-Item $TmpSql
    if ($dumpItem.Length -eq 0) {
        throw "El volcado generó un archivo vacío."
    }

    Write-Host "[OK] Volcado atómico completado: $($dumpItem.Length) bytes" -ForegroundColor Green

    # 4. Compresión Gzip
    Write-Host "[*] Comprimiendo volcado en gzip..." -ForegroundColor Yellow
    $inStream = [System.IO.File]::OpenRead($TmpSql)
    $outStream = [System.IO.File]::Create($FinalGz)
    $gzStream = New-Object System.IO.Compression.GZipStream($outStream, [System.IO.Compression.CompressionLevel]::Optimal)
    $inStream.CopyTo($gzStream)
    $gzStream.Dispose()
    $outStream.Dispose()
    $inStream.Dispose()

    Remove-Item $TmpSql -Force

    $gzItem = Get-Item $FinalGz
    Write-Host "[OK] Archivo comprimido generado: $($gzItem.Name) ($($gzItem.Length) bytes)" -ForegroundColor Green

    # 5. Generación de Firma SHA-256
    Write-Host "[*] Generando firma criptográfica SHA-256..." -ForegroundColor Yellow
    $hash = (Get-FileHash -Path $FinalGz -Algorithm SHA256).Hash.ToLower()
    "$hash  $($gzItem.Name)" | Out-File -FilePath $ShaFile -Encoding ascii
    Write-Host "[OK] Checksum SHA-256: $hash" -ForegroundColor Green

    # 6. Retención (Poda de archivos > 7 días)
    Write-Host "[*] Aplicando política de retención (poda > 7 días)..."
    $limitDate = (Get-Date).AddDays(-7)
    Get-ChildItem -Path $BackupDir -Filter "respaldo_sicm_*" | Where-Object { $_.LastWriteTime -lt $limitDate } | Remove-Item -Force

    # 7. Registrar en logs_auditoria
    wsl -e docker exec $ContainerName mariadb --defaults-extra-file=/tmp/.dump_creds.cnf $DbName -e "
        INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, detalles_json, ip_origen, user_agent, fecha_hora)
        VALUES (NULL, 'RESPALDO_DAEMON_CRON', 'CONTROL_SISTEMA', '{\`"tipo\`":\`"POWERSHELL_DAEMON\`",\`"archivo\`":\`"$($gzItem.Name)\`",\`"bytes\`":$($gzItem.Length),\`"sha256\`":\`"$hash\`",\`"retencion_dias\`":7}', '127.0.0.1', 'PowerShell Daemon Windows', NOW());
    " 2>$null

    Write-Host "=================================================================" -ForegroundColor Cyan
    Write-Host "  RESPALDO EXITOSO Y CERTIFICADO: $($gzItem.Name)" -ForegroundColor Cyan
    Write-Host "=================================================================" -ForegroundColor Cyan

} finally {
    wsl -e docker exec $ContainerName rm -f /tmp/.dump_creds.cnf 2>$null
    if (Test-Path $TmpSql) { Remove-Item $TmpSql -Force 2>$null }
}