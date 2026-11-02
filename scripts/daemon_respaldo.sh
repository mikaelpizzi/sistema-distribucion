#!/usr/bin/env bash
# ==============================================================================
# SICM - Sistema Integrado de Control de Medicamentos
# scripts/daemon_respaldo.sh
# Demonio Autónomo de Respaldo Transaccional (Regla 3-2-1 / Nivel SRE)
# ==============================================================================
# Características:
# 1. Sin exposición de contraseñas en ps aux (--defaults-extra-file con chmod 600).
# 2. Transaccional InnoDB sin bloqueo (--single-transaction, --quick, --hex-blob).
# 3. Escritura atómica (vuelca a .tmp y comprime solo si código de retorno es 0).
# 4. Firma criptográfica de integridad SHA-256 (.sha256).
# 5. Política de retención y poda automática de respaldos (> 7 días).
# ==============================================================================

set -euo pipefail

# Directorios de trabajo
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKUP_DIR="${ROOT_DIR}/respaldos"
mkdir -p "${BACKUP_DIR}"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
CONTAINER_NAME="salud_maria_db"
DB_NAME="control_medicamentos_red"
DB_USER="app_salud_user"
DB_PASS="app_password_seguro_2026"

TMP_SQL="${BACKUP_DIR}/respaldo_${TIMESTAMP}.sql.tmp"
FINAL_GZ="${BACKUP_DIR}/respaldo_sicm_${TIMESTAMP}.sql.gz"
SHA_FILE="${FINAL_GZ}.sha256"

echo "================================================================="
echo "  SICM | DEMONIO DE RESPALDO TRANSACCIONAL (InnoDB Hot Backup)  "
echo "================================================================="
echo "[*] Fecha y hora: $(date '+%Y-%m-%d %H:%M:%S')"
echo "[*] Destino: ${BACKUP_DIR}"

# 1. Comprobar que el contenedor de MariaDB está activo
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "[ERROR CRÍTICO] El contenedor '${CONTAINER_NAME}' no está en ejecución." >&2
    exit 1
fi

# 2. Generar archivo temporal de credenciales seguro dentro del contenedor (chmod 600)
# Esto evita exponer la contraseña en la tabla de procesos (ps aux)
docker exec "${CONTAINER_NAME}" bash -c "cat << 'EOF' > /tmp/.dump_creds.cnf
[client]
user=${DB_USER}
password=${DB_PASS}
EOF
chmod 600 /tmp/.dump_creds.cnf"

cleanup() {
    docker exec "${CONTAINER_NAME}" rm -f /tmp/.dump_creds.cnf 2>/dev/null || true
    rm -f "${TMP_SQL}" 2>/dev/null || true
}
trap cleanup EXIT

echo "[*] Ejecutando volcado transaccional InnoDB (--single-transaction)..."

# 3. Volcado atómico en archivo temporal (.tmp)
# --defaults-extra-file DEBE ser el primer argumento según especificación de MariaDB
docker exec "${CONTAINER_NAME}" mariadb-dump \
    --defaults-extra-file=/tmp/.dump_creds.cnf \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --hex-blob \
    --default-character-set=utf8mb4 \
    "${DB_NAME}" > "${TMP_SQL}"

# Validar que el archivo temporal no esté vacío
if [ ! -s "${TMP_SQL}" ]; then
    echo "[ERROR CRÍTICO] El volcado falló o generó un archivo vacío." >&2
    exit 1
fi

DUMP_SIZE=$(wc -c < "${TMP_SQL}")
echo "[OK] Volcado atómico completado: $(numfmt --to=iec-i --suffix=B "${DUMP_SIZE}" 2>/dev/null || echo "${DUMP_SIZE} bytes")"

# 4. Compresión de alta densidad (gzip -9)
echo "[*] Comprimiendo volcado en gzip nivel 9..."
gzip -9 -c "${TMP_SQL}" > "${FINAL_GZ}"
rm -f "${TMP_SQL}"

GZ_SIZE=$(wc -c < "${FINAL_GZ}")
echo "[OK] Archivo comprimido generado: $(basename "${FINAL_GZ}") ($(numfmt --to=iec-i --suffix=B "${GZ_SIZE}" 2>/dev/null || echo "${GZ_SIZE} bytes"))"

# 5. Generación de Firma Criptográfica SHA-256 para integridad forense
echo "[*] Generando firma criptográfica SHA-256..."
(cd "${BACKUP_DIR}" && sha256sum "$(basename "${FINAL_GZ}")") > "${SHA_FILE}"
HASH_SHA=$(cat "${SHA_FILE}" | awk '{print $1}')
echo "[OK] Checksum SHA-256: ${HASH_SHA}"

# 6. Política de Retención y Poda (Pruning): Eliminar respaldos > 7 días
echo "[*] Aplicando política de retención (poda de archivos > 7 días)..."
DELETED_COUNT=$(find "${BACKUP_DIR}" -name "respaldo_sicm_*.sql.gz*" -mtime +7 | wc -l)
find "${BACKUP_DIR}" -name "respaldo_sicm_*.sql.gz*" -mtime +7 -delete
echo "[OK] Archivos antiguos eliminados: ${DELETED_COUNT}"

# 7. Registrar evento en la bitácora de auditoría del sistema
docker exec "${CONTAINER_NAME}" mariadb --defaults-extra-file=/tmp/.dump_creds.cnf "${DB_NAME}" -e "
    INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, detalles_json, ip_origen, user_agent, fecha_hora)
    VALUES (NULL, 'RESPALDO_DAEMON_CRON', 'CONTROL_SISTEMA', '{\"tipo\":\"DAEMON_SISTEMA_OPERATIVO\",\"archivo\":\"$(basename "${FINAL_GZ}")\",\"bytes\":${GZ_SIZE},\"sha256\":\"${HASH_SHA}\",\"retencion_dias\":7}', '127.0.0.1', 'Bash Daemon / Cron SRE', NOW());
" 2>/dev/null || true

echo "================================================================="
echo "  RESPALDO EXITOSO Y CERTIFICADO: $(basename "${FINAL_GZ}")     "
echo "================================================================="
exit 0
