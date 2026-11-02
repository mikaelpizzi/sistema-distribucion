<?php
// src/logout.php
// Cierre seguro de sesión de operador
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';

$usuario = obtenerUsuarioSesion();
if ($usuario) {
    try {
        $pdo = getDB();
        registrarAuditoria($pdo, $usuario['id'], 'CIERRE_SESION', 'usuarios', $usuario['id'], [
            'evento' => 'Cierre de sesión seguro de operador',
            'email'  => $usuario['email'],
            'rol'    => $usuario['rol_nombre']
        ]);
    } catch (Throwable $e) {}
}

cerrarSesion();
header('Location: login.php?msg=' . urlencode('Ha cerrado su sesión de forma segura.'));
exit;

