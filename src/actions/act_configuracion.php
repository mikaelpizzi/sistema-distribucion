<?php
declare(strict_types=1);

/**
 * Action Handler: Configuración Institucional y Respaldo de Base de Datos
 * Sistema Integrado de Control de Medicamentos (SICM)
 */

if (!defined('SICM_CONTROLADOR')) {
    http_response_code(403);
    exit('Acceso denegado.');
}

switch ($accion) {
    case 'descargar_respaldo':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validarCsrfToken($_POST['csrf_token'] ?? '')) {
                header('Location: index.php?tab=configuracion&error=' . urlencode('Sesión expirada o token CSRF inválido.'));
                exit;
            }
        } elseif (!tienePermiso('SISTEMA_RESPALDO')) {
            header('Location: index.php?error=' . urlencode('Acceso denegado: Se requieren permisos de Respaldo de Base de Datos.'));
            exit;
        }

        // 1. Trazabilidad Forense Obligatoria (WORM Audit Trail)
        registrarAuditoria($pdo, $usuarioSesionId, 'EXPORTAR_RESPALDO_BD', 'CONTROL_SISTEMA', null, [
            'evento' => 'Descarga manual bajo demanda de copia de seguridad caliente (Full Dump)',
            'metodo' => 'Streaming O(1) MVCC mariadb-dump + gzip',
            'ip_remota' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI / Local'
        ]);

        // 2. Limpieza exhaustiva de cualquier búfer de salida activo para prevenir corrupción binaria
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // 3. Parámetros de conexión desde entorno o defaults
        $host = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $db = $_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? (getenv('DB_NAME') ?: 'control_medicamentos_red');
        $user = $_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? (getenv('DB_USER') ?: 'app_salud_user');
        $pass = $_ENV['DB_PASS'] ?? $_SERVER['DB_PASS'] ?? (getenv('DB_PASS') ?: 'app_password_seguro_2026');

        $fecha = date('Ymd_His');
        $filename = "respaldo_sicm_{$fecha}.sql.gz";

        // 4. Encabezados HTTP RFC 1952 / RFC 2616 para descarga binaria directa
        header('Content-Type: application/gzip');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Expires: 0');

        // 5. Invocación segura de mariadb-dump con compresión gzip en streaming O(1)
        $dumpCmd = 'mariadb-dump -h ' . escapeshellarg($host) . ' -u ' . escapeshellarg($user)
            . ' --single-transaction --quick --routines --triggers --events --hex-blob '
            . escapeshellarg($db) . ' | gzip -c -9';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];

        $env = [
            'MYSQL_PWD' => $pass,
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'
        ];

        $process = proc_open(
            ['bash', '-c', 'set -o pipefail; ' . $dumpCmd],
            $descriptors,
            $pipes,
            null,
            $env
        );

        if (is_resource($process)) {
            fclose($pipes[0]);
            fpassthru($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0 && !empty($stderr)) {
                error_log("SICM Backup Error: " . $stderr);
            }
        } else {
            http_response_code(500);
            echo "Error interno de servidor: No se pudo invocar el proceso de volcado.";
        }
        exit;

    case 'guardar_identidad_institucional':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=configuracion');
            exit;
        }

        $nombreSistema = trim($_POST['nombre_sistema'] ?? '');
        $siglasSistema = strtoupper(trim($_POST['siglas_sistema'] ?? ''));
        $enteRector = trim($_POST['ente_rector'] ?? '');
        $municipio = trim($_POST['municipio'] ?? '');
        $diasFefo = (int) ($_POST['dias_alerta_fefo'] ?? 60);
        $eliminarLogo = !empty($_POST['eliminar_logo']);
        $logotipoRuta = trim($_POST['logotipo_ruta'] ?? '');

        if (mb_strlen($nombreSistema, 'UTF-8') < 3 || mb_strlen($siglasSistema, 'UTF-8') < 2 || mb_strlen($enteRector, 'UTF-8') < 3) {
            header('Location: index.php?tab=configuracion&error=' . urlencode('Los campos Nombre del Sistema, Siglas y Ente Rector son obligatorios.'));
            exit;
        }

        if ($diasFefo < 1 || $diasFefo > 365) {
            header('Location: index.php?tab=configuracion&error=' . urlencode('El umbral de alerta FEFO debe situarse entre 1 y 365 días.'));
            exit;
        }

        $actual = obtenerConfiguracionInstitucional($pdo);
        $rutaFinalLogo = $actual['logotipo_ruta'];

        if ($eliminarLogo) {
            $rutaFinalLogo = null;
        }

        // Procesar carga de archivo de imagen si fue suministrado
        if (isset($_FILES['logotipo_archivo']) && $_FILES['logotipo_archivo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logotipo_archivo'];
            $maxBytes = 2 * 1024 * 1024; // 2 MB
            if ($file['size'] > $maxBytes) {
                header('Location: index.php?tab=configuracion&error=' . urlencode('El archivo de imagen no debe superar los 2 MB.'));
                exit;
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $permitidos = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/svg+xml' => 'svg',
                'image/webp' => 'webp'
            ];

            if (!isset($permitidos[$mime])) {
                header('Location: index.php?tab=configuracion&error=' . urlencode('Formato de imagen no permitido. Admite PNG, JPG, SVG y WEBP.'));
                exit;
            }

            $uploadDir = __DIR__ . '/../public/img';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $ext = $permitidos[$mime];
            $destName = 'logo_institucional_' . time() . '.' . $ext;
            $destPath = $uploadDir . '/' . $destName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $rutaFinalLogo = 'public/img/' . $destName;
                $archivoSubidoRuta = $destPath;
            }
        } elseif (!empty($logotipoRuta)) {
            $rutaFinalLogo = $logotipoRuta;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare("
            UPDATE configuracion_institucional
            SET nombre_sistema = :nombre,
                siglas_sistema = :siglas,
                ente_rector = :ente,
                municipio = :municipio,
                logotipo_ruta = :logo,
                dias_alerta_fefo = :dias
            WHERE id = 1
        ");
        $stmt->execute([
            ':nombre' => $nombreSistema,
            ':siglas' => $siglasSistema,
            ':ente' => $enteRector,
            ':municipio' => $municipio,
            ':logo' => $rutaFinalLogo,
            ':dias' => $diasFefo
        ]);

        // Auditoría forense del cambio con delta detallado
        registrarAuditoria($pdo, $usuarioSesionId, 'ACTUALIZAR_IDENTIDAD_INSTITUCIONAL', 'configuracion_institucional', 1, [
            'evento' => 'Actualización de Identidad Institucional y Parametrización FEFO',
            'delta' => [
                'nombre_sistema' => ['antes' => $actual['nombre_sistema'], 'despues' => $nombreSistema],
                'siglas_sistema' => ['antes' => $actual['siglas_sistema'], 'despues' => $siglasSistema],
                'ente_rector' => ['antes' => $actual['ente_rector'], 'despues' => $enteRector],
                'municipio' => ['antes' => $actual['municipio'], 'despues' => $municipio],
                'dias_alerta_fefo' => ['antes' => $actual['dias_alerta_fefo'], 'despues' => $diasFefo],
                'logotipo_ruta' => ['antes' => $actual['logotipo_ruta'], 'despues' => $rutaFinalLogo]
            ]
        ]);

        $pdo->commit();
        header('Location: index.php?tab=configuracion&msg=' . urlencode('Identidad institucional y parámetros actualizados exitosamente.'));
        exit;

    default:
        header('Location: index.php?tab=configuracion');
        exit;
}
