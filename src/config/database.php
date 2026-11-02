<?php
// src/config/database.php
// Conexión nativa a MariaDB/MySQL mediante PDO sin librerías externas
declare(strict_types=1);

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
    }

    return $pdo;
}

