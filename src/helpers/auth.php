<?php
// src/helpers/auth.php
// Módulo de Autenticación, Control de Acceso Basado en Roles (RBAC) y Seguridad de Sesión
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Cuenta cuántos usuarios existen en el sistema para determinar si procede el setup inicial.
 */
function contarUsuariosRegistrados(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Obtiene los datos del usuario en la sesión activa, o null si no está autenticado.
 */
function obtenerUsuarioSesion(): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }

    return [
        'id'              => (int)$_SESSION['usuario_id'],
        'nombre'          => (string)($_SESSION['usuario_nombre'] ?? ''),
        'email'           => (string)($_SESSION['usuario_email'] ?? ''),
        'rol_id'          => (int)($_SESSION['rol_id'] ?? 0),
        'rol_nombre'      => (string)($_SESSION['rol_nombre'] ?? ''),
        'centro_salud_id' => isset($_SESSION['centro_salud_id']) && $_SESSION['centro_salud_id'] !== null ? (int)$_SESSION['centro_salud_id'] : null,
        'centro_nombre'   => (string)($_SESSION['centro_nombre'] ?? 'Red Municipal Central')
    ];
}

/**
 * Verifica contraseñas contra hash Bcrypt/Argon2 o SHA-256 legacy de la base de datos.
 */
function verificarPassword(string $password, string $hashAlmacenado): bool
{
    // 1. Compatibilidad con SHA-256 nativo de seeders iniciales (64 caracteres hexadecimales)
    if (strlen($hashAlmacenado) === 64 && ctype_xdigit($hashAlmacenado)) {
        return hash_equals(hash('sha256', $password), $hashAlmacenado);
    }

    // 2. Hash estándar nativo de PHP (Bcrypt / Argon2id)
    return password_verify($password, $hashAlmacenado);
}

/**
 * Intenta autenticar un usuario por email y contraseña.
 */
function autenticarUsuario(PDO $pdo, string $email, string $password): ?array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.nombre, u.email, u.password_hash, u.rol_id, r.nombre AS rol_nombre, 
               u.centro_salud_id, cs.nombre AS centro_nombre, u.activo
        FROM usuarios u
        INNER JOIN roles r ON r.id = u.rol_id
        LEFT JOIN centros_salud cs ON cs.id = u.centro_salud_id
        WHERE LOWER(TRIM(u.email)) = LOWER(TRIM(:email))
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        return ['error' => 'usuario_no_encontrado'];
    }

    if ((int)$usuario['activo'] !== 1) {
        return ['error' => 'cuenta_inactiva'];
    }

    if (!verificarPassword($password, $usuario['password_hash'])) {
        return ['error' => 'password_incorrecto'];
    }

    // Iniciar sesión y regenerar ID para prevenir fijación de sesión
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['usuario_id']        = (int)$usuario['id'];
    $_SESSION['usuario_nombre']    = $usuario['nombre'];
    $_SESSION['usuario_email']     = $usuario['email'];
    $_SESSION['rol_id']            = (int)$usuario['rol_id'];
    $_SESSION['rol_nombre']        = $usuario['rol_nombre'];
    $_SESSION['centro_salud_id']   = $usuario['centro_salud_id'] !== null ? (int)$usuario['centro_salud_id'] : null;
    $_SESSION['centro_nombre']     = $usuario['centro_nombre'] ?? 'Red Municipal Central';
    $_SESSION['sesion_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI';
    $_SESSION['sesion_ip']         = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    return $usuario;
}

/**
 * Guardián de seguridad: Requiere autenticación obligatoria.
 * Redirige a setup.php si la base de datos está vacía, o a login.php si no hay sesión.
 */
function requerirAutenticacion(PDO $pdo): array
{
    // 1. Si no hay usuarios en la BD, forzar el asistente de primer arranque (Setup)
    if (contarUsuariosRegistrados($pdo) === 0) {
        header('Location: setup.php');
        exit;
    }

    // 2. Si no hay sesión iniciada, enviar a login
    $usuario = obtenerUsuarioSesion();
    if (!$usuario) {
        header('Location: login.php');
        exit;
    }

    // 3. Verificación de integridad de sesión del cliente
    $uaActual = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI';
    $uaPrevio = $_SESSION['sesion_user_agent'] ?? null;
    if ($uaPrevio !== null && $uaPrevio !== $uaActual) {
        cerrarSesion();
        $msg = sprintf(
            'Sesión revocada por seguridad: Cambió el identificador del navegador (User-Agent modificado). Anterior: [%.40s...] | Actual: [%.40s...]. Si activó o desactivó la emulación móvil en DevTools (F12), inicie sesión nuevamente.',
            $uaPrevio,
            $uaActual
        );
        header('Location: login.php?error=' . urlencode($msg));
        exit;
    }

    // Mantener la IP sincronizada sin invalidar la sesión (previene falsos positivos en redes móviles o puentes Docker)
    $ipActual = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SESSION['sesion_ip']) && $_SESSION['sesion_ip'] !== $ipActual) {
        $_SESSION['sesion_ip'] = $ipActual;
    }

    // 4. Verificar en BD que el usuario siga activo (evita que un usuario desactivado siga navegando)
    $stmt = $pdo->prepare("SELECT activo FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $usuario['id']]);
    $activo = $stmt->fetchColumn();

    if ($activo !== false && (int)$activo !== 1) {
        cerrarSesion();
        header('Location: login.php?error=' . urlencode("Acceso revocado: La cuenta del operador #{$usuario['id']} ({$usuario['email']}) fue desactivada en la base de datos (columna activo = 0)."));
        exit;
    }

    return $usuario;
}

/**
 * Cierra la sesión activa destruyendo tokens y cookies.
 */
function cerrarSesion(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}

