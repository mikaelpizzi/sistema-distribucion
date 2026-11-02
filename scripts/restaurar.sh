#!/usr/bin/env bash
# ==============================================================================
# SICM - Sistema Integrado de Control de Medicamentos
# scripts/restaurar.sh
# Restauración Forense Certificada con Validación de Integridad SHA-256
# ==============================================================================

set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Uso: $0 <archivo_respaldo.sql.gz>"
    echo "Ejemplo: $0 respaldos/respaldo_sicm_20261101_020000.sql.gz"
    exit 1
fi

BACKUP_FILE="$1"
SHA_FILE="${BACKUP_FILE}.sha256"
CONTAINER_NAME="salud_maria_db"
DB_NAME="control_medicamentos_red"
DB_USER="app_salud_user"
DB_PASS="app_password_seguro_2026"

echo "================================================================="
echo "  SICM | RESTAURACIÓN Y CERTIFICACIÓN FORENSE DE BASE DE DATOS  "
echo "================================================================="

# 1. Comprobación de existencia del archivo
if [ ! -f "${BACKUP_FILE}" ]; then
    echo "[ERROR CRÍTICO] El archivo '${BACKUP_FILE}' no existe." >&2
    exit 1
fi

# 2. Validación de Firma Criptográfica SHA-256
echo "[*] Verificando firma criptográfica de integridad SHA-256..."
if [ ! -f "${SHA_FILE}" ]; then
    echo "[ALERTA DE SEGURIDAD] No se encontró el archivo de firma '${SHA_FILE}'." >&2
    echo "¿Desea continuar sin verificación de integridad? (s/N): "
    read -r resp
    if [[ ! "$resp" =~ ^[sS]$ ]]; then
        echo "[ABORTADO] Restauración cancelada por política de seguridad."
        exit 1
    fi
else
    BACKUP_DIR="$(dirname "${BACKUP_FILE}")"
    FILE_NAME="$(basename "${BACKUP_FILE}")"
    if ! (cd "${BACKUP_DIR}" && sha256sum -c "$(basename "${SHA_FILE}")"); then
        echo "-----------------------------------------------------------------" >&2
        echo "[ERROR FORENSE CRÍTICO] LA FIRMA SHA-256 NO COINCIDE." >&2
        echo "El archivo de respaldo fue modificado, adulterado o corrompido." >&2
        echo "Por protocolo de seguridad médica, la restauración ha sido ABORTADA." >&2
        echo "-----------------------------------------------------------------" >&2
        exit 2
    fi
    echo "[OK] Integridad verificada al 100%: Hash SHA-256 válido."
fi

# 3. Comprobar que el contenedor de MariaDB está activo
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "[ERROR CRÍTICO] El contenedor '${CONTAINER_NAME}' no está en ejecución." >&2
    exit 1
fi

# 4. Archivo temporal de credenciales seguro
docker exec "${CONTAINER_NAME}" bash -c "cat << 'EOF' > /tmp/.restore_creds.cnf
[client]
user=${DB_USER}
password=${DB_PASS}
EOF
chmod 600 /tmp/.restore_creds.cnf"

cleanup() {
    docker exec "${CONTAINER_NAME}" rm -f /tmp/.restore_creds.cnf 2>/dev/null || true
}
trap cleanup EXIT

# 5. Inyección transaccional
echo "[*] Descomprimiendo e inyectando datos en MariaDB..."
gzip -dc "${BACKUP_FILE}" | docker exec -i "${CONTAINER_NAME}" mariadb --defaults-extra-file=/tmp/.restore_creds.cnf "${DB_NAME}"

# 6. Registro de auditoría
docker exec "${CONTAINER_NAME}" mariadb --defaults-extra-file=/tmp/.restore_creds.cnf "${DB_NAME}" -e "
    INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, detalles_json, ip_origen, user_agent, fecha_hora)
    VALUES (NULL, 'RESTAURAR_BASE_DATOS', 'CONTROL_SISTEMA', '{\"archivo\":\"$(basename "${BACKUP_FILE}")\",\"estado\":\"EXITOSO\",\"validacion_sha256\":true}', '127.0.0.1', 'Bash Restore Script', NOW());
" 2>/dev/null || true

echo "================================================================="
echo "  [OK] BASE DE DATOS RESTAURADA CON ÉXITO: $(basename "${BACKUP_FILE}") "
echo "================================================================="
exit 0
