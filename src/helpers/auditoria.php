<?php
// src/helpers/auditoria.php
// Helper de Auditoría Inmutable WORM (Write Once, Read Many) y Compatibilidad JSON
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Registra un evento en la bitácora inmutable de auditoría (logs_auditoria).
 * Garantiza que detalles_json contenga siempre JSON válido o NULL para evitar
 * violaciones de restricción CHECK en motores MariaDB/MySQL.
 */
function registrarAuditoria(
    PDO $pdo,
    ?int $usuarioId,
    string $accion,
    string $tablaAfectada,
    ?int $registroId,
    array|string|null $detalles = []
): bool {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/Cron', 0, 255);

        // Sanitización y validación estricta de JSON para columnas de tipo JSON nativo
        $json = null;
        if (is_array($detalles) && !empty($detalles)) {
            $json = json_encode($detalles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = json_encode(['error' => 'error_serializacion_json_auditoria']);
            }
        } elseif (is_string($detalles) && trim($detalles) !== '') {
            $trimmed = trim($detalles);
            json_decode($trimmed);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $trimmed;
            } else {
                $json = json_encode(['mensaje' => $trimmed], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

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
 * Alias explícito compatible para registrar logs de auditoría WORM.
 */
function registrarLogAuditoria(
    PDO $pdo,
    ?int $usuarioId,
    string $accion,
    string $tablaAfectada,
    ?int $registroId,
    array|string|null $detalles = []
): bool {
    return registrarAuditoria($pdo, $usuarioId, $accion, $tablaAfectada, $registroId, $detalles);
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

/**
 * Traduce nombres técnicos de tablas e IDs a etiquetas descriptivas institucionales.
 */
function resolverEntidadLegible(
    string $tabla,
    ?int $registroId,
    array $detalles = [],
    array $diccionarios = []
): array {
    $tabla = strtolower(trim($tabla));
    $regId = $registroId !== null ? (int)$registroId : null;

    $nombresTablas = [
        'centros_salud'       => 'Ambulatorio',
        'recursos_operativos' => 'Medicamento / Insumo',
        'tipos_centro_salud'  => 'Tipo de Centro',
        'usuarios'            => 'Usuario del Sistema',
        'roles'               => 'Rol de Usuario',
        'seguridad'           => 'Seguridad del Sistema',
        'auditoria'           => 'Módulo de Auditoría',
        'control_sistema'     => 'Configuración del Sistema',
        'medicamentos'        => 'Fármaco / Vademécum',
        'patologias'          => 'Patología CIE-10',
        'formas_farmaceuticas'=> 'Forma Farmacéutica',
        'pacientes'           => 'Paciente / Padrón',
        'paciente_contactos'  => 'Contacto de Paciente',
    ];
    $entidadGeneral = $nombresTablas[$tabla] ?? ucfirst(str_replace('_', ' ', $tabla ?: 'Sistema'));

    $titulo = '';
    $subtitulo = '';

    if ($tabla === 'centros_salud') {
        if ($regId && isset($diccionarios['centros'][$regId])) {
            $c = $diccionarios['centros'][$regId];
            $titulo = $c['nombre'];
            $subtitulo = 'Ambulatorio [ID: ' . $regId . ']' . (!empty($c['codigo_sicm']) ? ' • ' . $c['codigo_sicm'] : '');
        } else {
            $nombre = $detalles['nombre'] ?? null;
            $titulo = $nombre ?: ($regId ? "Ambulatorio [ID: {$regId}]" : 'Ambulatorio');
            $subtitulo = $regId ? "Ambulatorio [ID: {$regId}]" : 'Ambulatorios';
        }
    } elseif ($tabla === 'medicamentos') {
        if ($regId && isset($diccionarios['medicamentos'][$regId])) {
            $m = $diccionarios['medicamentos'][$regId];
            $titulo = $m['nombre'] . (!empty($m['concentracion']) ? ' (' . $m['concentracion'] . ')' : '');
            $subtitulo = 'Medicamento [ID: ' . $regId . ']';
        } else {
            $nombre = $detalles['nombre_generico'] ?? ($detalles['nombre'] ?? null);
            $conc = $detalles['concentracion'] ?? '';
            $titulo = $nombre ? ($nombre . ($conc ? " ({$conc})" : '')) : ($regId ? "Medicamento [ID: {$regId}]" : 'Medicamento');
            $subtitulo = $regId ? "Medicamento [ID: {$regId}]" : 'Vademécum de Medicamentos';
        }
    } elseif ($tabla === 'patologias') {
        if ($regId && isset($diccionarios['patologias'][$regId])) {
            $p = $diccionarios['patologias'][$regId];
            $titulo = (!empty($p['codigo_cie10']) ? '[' . $p['codigo_cie10'] . '] ' : '') . $p['nombre'];
            $subtitulo = 'Patología [ID: ' . $regId . ']';
        } else {
            $nombre = $detalles['nombre'] ?? null;
            $cie = $detalles['codigo_cie10'] ?? '';
            $titulo = $nombre ? (($cie ? "[{$cie}] " : '') . $nombre) : ($regId ? "Patología [ID: {$regId}]" : 'Patología');
            $subtitulo = $regId ? "Patología [ID: {$regId}]" : 'Catálogo de Patologías';
        }
    } elseif ($tabla === 'formas_farmaceuticas') {
        if ($regId && isset($diccionarios['formas_farmaceuticas'][$regId])) {
            $f = $diccionarios['formas_farmaceuticas'][$regId];
            $titulo = $f['nombre'];
            $subtitulo = 'Forma Farmacéutica [ID: ' . $regId . ']';
        } else {
            $nombre = $detalles['nombre'] ?? null;
            $titulo = $nombre ?: ($regId ? "Forma [ID: {$regId}]" : 'Forma Farmacéutica');
            $subtitulo = $regId ? "Forma [ID: {$regId}]" : 'Formas Farmacéuticas';
        }
    } elseif ($tabla === 'recursos_operativos') {
        if ($regId && isset($diccionarios['recursos'][$regId])) {
            $r = $diccionarios['recursos'][$regId];
            $titulo = $r['nombre'];
            $subtitulo = 'Medicamento [ID: ' . $regId . ']' . (!empty($r['codigo']) ? ' • ' . $r['codigo'] : '');
        } else {
            $nombre = $detalles['nombre'] ?? null;
            $titulo = $nombre ?: ($regId ? "Medicamento [ID: {$regId}]" : 'Medicamento');
            $subtitulo = $regId ? "Medicamento / Insumo [ID: {$regId}]" : 'Medicamentos';
        }
    } elseif ($tabla === 'tipos_centro_salud') {
        if ($regId && isset($diccionarios['tipos'][$regId])) {
            $t = $diccionarios['tipos'][$regId];
            $titulo = $t['nombre'];
            $subtitulo = 'Tipo de Centro [ID: ' . $regId . ']';
        } else {
            $nombre = $detalles['nombre'] ?? null;
            $titulo = $nombre ?: ($regId ? "Tipo [ID: {$regId}]" : 'Tipo de Centro');
            $subtitulo = $regId ? "Tipo de Centro [ID: {$regId}]" : 'Tipos de Centro';
        }
    } elseif ($tabla === 'usuarios') {
        if ($regId && isset($diccionarios['usuarios'][$regId])) {
            $u = $diccionarios['usuarios'][$regId];
            $titulo = $u['nombre'];
            $subtitulo = 'Usuario [ID: ' . $regId . ']' . (!empty($u['email']) ? ' • ' . $u['email'] : '');
        } else {
            $nombre = $detalles['nombre'] ?? ($detalles['email'] ?? null);
            $titulo = $nombre ?: ($regId ? "Usuario [ID: {$regId}]" : 'Usuario');
            $subtitulo = $regId ? "Usuario [ID: {$regId}]" : 'Usuarios';
        }
    } elseif ($tabla === 'pacientes') {
        if ($regId && isset($diccionarios['pacientes'][$regId])) {
            $p = $diccionarios['pacientes'][$regId];
            $titulo = $p['nombre_completo'] . (!empty($p['cedula']) ? ' (' . $p['cedula'] . ')' : '');
            $subtitulo = 'Paciente [ID: ' . $regId . ']';
        } else {
            $nombre = $detalles['nombre_completo'] ?? ($detalles['nombre'] ?? null);
            $ced = $detalles['cedula'] ?? '';
            $titulo = $nombre ? ($nombre . ($ced ? " ({$ced})" : '')) : ($regId ? "Paciente [ID: {$regId}]" : 'Paciente');
            $subtitulo = $regId ? "Paciente [ID: {$regId}]" : 'Padrón de Pacientes';
        }
    } else {
        $evento = $detalles['evento'] ?? null;
        $titulo = $evento ?: ($regId ? "{$entidadGeneral} [ID: {$regId}]" : $entidadGeneral);
        $subtitulo = $regId ? "{$entidadGeneral} [ID: {$regId}]" : $entidadGeneral;
    }

    return [
        'titulo'        => $titulo,
        'subtitulo'     => $subtitulo,
        'entidad'       => $entidadGeneral,
        'nombre_corto'  => ($titulo !== $subtitulo && !str_starts_with($titulo, $entidadGeneral)) ? $titulo : ''
    ];
}


