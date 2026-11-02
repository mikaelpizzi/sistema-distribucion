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

/**
 * Genera un resumen legible del Agente de Usuario (Navegador y Sistema Operativo).
 */
function resumirUserAgent(?string $ua): string
{
    if (!$ua || in_array($ua, ['CLI / Local', 'CLI/Cron'], true)) {
        return 'Consola / CLI';
    }

    $os = 'SO';
    if (str_contains($ua, 'Windows NT 10.0')) $os = 'Windows 10/11';
    elseif (str_contains($ua, 'Windows')) $os = 'Windows';
    elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS')) $os = 'macOS';
    elseif (str_contains($ua, 'Android')) $os = 'Android';
    elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';
    elseif (str_contains($ua, 'Linux')) $os = 'Linux';

    $browser = 'Navegador';
    if (str_contains($ua, 'Edg/')) $browser = 'Edge';
    elseif (str_contains($ua, 'Chrome/') && !str_contains($ua, 'Edg/')) $browser = 'Chrome';
    elseif (str_contains($ua, 'Firefox/')) $browser = 'Firefox';
    elseif (str_contains($ua, 'Safari/') && !str_contains($ua, 'Chrome')) $browser = 'Safari';
    elseif (str_contains($ua, 'curl/')) $browser = 'cURL';
    elseif (str_contains($ua, 'Postman')) $browser = 'Postman';
    elseif (str_contains($ua, 'python')) $browser = 'Python';

    return "{$browser} ({$os})";
}


