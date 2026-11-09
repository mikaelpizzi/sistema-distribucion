<?php
// src/config/database.php
// Conexión nativa a MariaDB/MySQL mediante PDO sin librerías externas
declare(strict_types=1);

// Sincronizar zona horaria de la aplicación con la hora legal de Venezuela (UTC-4)
date_default_timezone_set('America/Caracas');

/**
 * Establece las variables de contexto de sesión SQL (@app_user_id, @app_client_ip, @app_user_agent)
 * en la conexión PDO activa para que los triggers de auditoría registren al operador web real.
 */
function establecerContextoAuditoriaSQL(
    PDO $pdo,
    ?int $usuarioId = null,
    ?string $ip = null,
    ?string $userAgent = null
): void {
    try {
        $ip = $ip ?? ($_SESSION['sesion_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        $userAgent = $userAgent ?? ($_SESSION['sesion_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'CLI'));

        $usrSql = $usuarioId !== null ? (int)$usuarioId : 'NULL';
        $ipSql  = $ip !== null ? $pdo->quote(substr($ip, 0, 45)) : 'NULL';
        $uaSql  = $userAgent !== null ? $pdo->quote(substr($userAgent, 0, 255)) : 'NULL';

        $pdo->exec("SET @app_user_id = {$usrSql}, @app_client_ip = {$ipSql}, @app_user_agent = {$uaSql};");
    } catch (Throwable $e) {
        error_log('[AUDITORIA SQL CONTEXT ERROR]: ' . $e->getMessage());
    }
}

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // En Docker, el host por defecto es 'db'; en ejecución local sin Docker es '127.0.0.1'
        $host = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $db   = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? (getenv('DB_NAME') ?: 'control_medicamentos_red');
        $user = $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? (getenv('DB_USER') ?: 'app_salud_user');
        $pass = $_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? (getenv('DB_PASS') ?: 'app_password_seguro_2026');

        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Excepciones ante fallos de integridad SQL
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Arreglos asociativos planos
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Prepared statements reales en el motor
        ];

        // Intentar conectar con la configuración principal
        $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $user, $pass, $opciones);
        } catch (PDOException $e) {
            // Reintento con fallback a 127.0.0.1 si se ejecuta localmente fuera de Docker
            try {
                $dsnLocal = "mysql:host=127.0.0.1;dbname={$db};charset=utf8mb4";
                $pdo = new PDO($dsnLocal, $user, $pass, $opciones);
            } catch (PDOException $e2) {
                // Reintento con fallback a control_medicamentos_salud si aplica
                try {
                    $dsnSalud = "mysql:host={$host};dbname=control_medicamentos_salud;charset=utf8mb4";
                    $pdo = new PDO($dsnSalud, $user, $pass, $opciones);
                } catch (PDOException $e3) {
                    http_response_code(500);
                    die("Error de Infraestructura: No fue posible conectar con la base de datos MySQL/MariaDB. " . $e->getMessage());
                }
            }
        }

        // Forzar zona horaria de MariaDB a UTC-4 (America/Caracas)
        try {
            $pdo->exec("SET time_zone = '-04:00';");
        } catch (Throwable $tzErr) {
            // Continuar si la directiva ya viene configurada por el servidor
        }

        // Si la sesión PHP ya está iniciada con un usuario activo, sincronizar variables SQL en la conexión
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['usuario_id'])) {
            establecerContextoAuditoriaSQL(
                $pdo,
                (int)$_SESSION['usuario_id'],
                $_SESSION['sesion_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                $_SESSION['sesion_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null)
            );
        }
    }

    return $pdo;
}

