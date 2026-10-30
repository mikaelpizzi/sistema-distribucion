<?php
// src/config/db.php
// Conexión a MariaDB / MySQL mediante PDO nativo (sin frameworks ni ORMs)

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $db = getenv('DB_NAME') ?: 'control_medicamentos_red';
        $user = getenv('DB_USER') ?: 'app_salud_user';
        $pass = getenv('DB_PASS') ?: 'app_password_seguro_2026';

        $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

        $opciones = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Lanza excepciones ante errores de SQL o restricciones
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retorna arreglos asociativos legibles
        ];

        $pdo = new PDO($dsn, $user, $pass, $opciones);
    }

    return $pdo;
}
