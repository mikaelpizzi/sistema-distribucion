<?php
// src/helpers/auditoria.php
// Helper de Auditoría Inmutable WORM (Write Once, Read Many)
declare(strict_types=1);

/**
 * Registra un evento en la bitácora inmutable de auditoría.
 */
function registrarAuditoria(
    PDO $pdo,
    ?int $usuarioId,
    string $accion,
    string $tablaAfectada,
    ?int $registroId,
    array $detalles = []
): bool {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Cron', 0, 255);
        $json = !empty($detalles) ? json_encode($detalles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $stmt = $pdo->prepare("
            INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles_json, ip_origen, user_agent)
            VALUES (:usuario_id, :accion, :tabla, :registro_id, :detalles, :ip, :ua)
        ");

        return $stmt->execute([
            ':usuario_id'   => $usuarioId,
            ':accion'       => strtoupper(trim($accion)),
            ':tabla'        => strtolower(trim($tablaAfectada)),
            ':registro_id'  => $registroId,
            ':detalles'     => $json,
            ':ip'           => $ip,
            ':ua'           => $ua
        ]);
    } catch (Throwable $e) {
        error_log('[AUDITORIA ERROR]: ' . $e->getMessage());
        return false;
    }
}

