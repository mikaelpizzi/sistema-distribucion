<?php
// src/helpers/auditoria.php
// Sistema Centralizado de Registro de Logs y Auditoría Forense (Misión 2)
declare(strict_types=1);

/**
 * Registra una acción administrativa u operativa en la bitácora inmutable logs_auditoria.
 * 
 * @param PDO $pdo Instancia activa de conexión PDO
 * @param int|null $usuarioId ID del operador responsable (NULL para acciones del sistema)
 * @param string $accion Código nemotécnico de la acción (Ej: CREAR_CENTRO, EDITAR_CENTRO, DESACTIVAR_CENTRO, REACTIVAR_CENTRO)
 * @param string $tablaAfectada Nombre de la tabla física en base de datos intervenida
 * @param int|null $registroId Clave primaria del registro intervenido
 * @param array $detalles Estructura asociativa con valores anteriores y nuevos (delta forense)
 * @return bool True si el registro fue insertado satisfactoriamente
 */
function registrarAuditoria(
    PDO $pdo,
    ?int $usuarioId,
    string $accion,
    string $tablaAfectada,
    ?int $registroId,
    array $detalles
): bool {
    try {
        // Detección de dirección IP de origen (con soporte para proxies o intranet)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        $ip = substr($ip, 0, 45);

        // Formateo del payload a JSON nativo UTF-8
        $detallesJson = json_encode($detalles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $sql = "INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles_json, ip_origen, fecha_hora)
                VALUES (:usuario_id, :accion, :tabla_afectada, :registro_id, :detalles_json, :ip_origen, NOW())";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':usuario_id'      => $usuarioId,
            ':accion'          => strtoupper(trim($accion)),
            ':tabla_afectada'  => trim($tablaAfectada),
            ':registro_id'     => $registroId,
            ':detalles_json'   => $detallesJson,
            ':ip_origen'       => $ip
        ]);
    } catch (Throwable $e) {
        // En auditoría forense empresarial, un fallo de log se reporta al error_log del servidor para no interrumpir silenciosamente
        error_log("[ERROR AUDITORIA]: No se pudo registrar log: " . $e->getMessage());
        return false;
    }
}

