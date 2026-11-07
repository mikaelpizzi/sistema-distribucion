<?php
// src/procesar.php
// Controlador Transaccional Centralizado (PHP Nativo / PDO - Cero ORM / Cero Frameworks)
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';

$pdo = getDB();
$accion = $_REQUEST['accion'] ?? '';

// Verificación obligatoria de autenticación para todas las operaciones del controlador
$usuario = obtenerUsuarioSesion();
if (!$usuario) {
    header('Location: login.php');
    exit;
}
$usuarioSesionId = $usuario['id'];
$esAdmin = ((int) $usuario['rol_id'] === 1);

// Guardián RBAC: Las operaciones de configuración y altas son exclusivas de rol_admin
$accionesSoloAdmin = [
    'guardar_centro',
    'cambiar_estado_centro',
    'crear_tipo_centro',
    'toggle_tipo_centro',
    'crear_recurso',
    'toggle_recurso',
    'guardar_usuario',
    'cambiar_estado_usuario',
    'descargar_respaldo'
];

if (in_array($accion, $accionesSoloAdmin, true) && !$esAdmin) {
    registrarAuditoria($pdo, $usuarioSesionId, 'ACCESO_DENEGADO', 'seguridad', null, [
        'evento' => 'Intento no autorizado de ejecutar operación administrativa',
        'accion_solicitada' => $accion,
        'rol_usuario' => $usuario['rol_nombre']
    ]);
    header('Location: index.php?error=' . urlencode('Acceso denegado: Se requieren privilegios de Administrador Central.'));
    exit;
}

try {
    switch ($accion) {
        // ======================================================================
        // 1. GESTIÓN DE AMBULATORIOS (CREATE / UPDATE)
        // ======================================================================
        case 'guardar_centro':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=centros');
                exit;
            }

            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $codigo = strtoupper(trim($_POST['codigo_sicm'] ?? ''));
            $nombre = trim($_POST['nombre'] ?? '');
            $tipoId = (int) ($_POST['tipo_centro_id'] ?? 0);
            $medicoDirector = trim($_POST['medico_director'] ?? '');
            $horarioAtencion = trim($_POST['horario_atencion'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');
            $telefono = trim($_POST['telefono_principal'] ?? '');
            $recursos = isset($_POST['recursos']) && is_array($_POST['recursos'])
                ? array_map('intval', $_POST['recursos'])
                : [];

            if (mb_strlen($codigo, 'UTF-8') < 3 || mb_strlen($nombre, 'UTF-8') < 4 || $tipoId <= 0 || mb_strlen($direccion, 'UTF-8') < 6) {
                header('Location: index.php?tab=centros&error=' . urlencode('Verifique los campos requeridos: Código SICM, Nombre, Clasificación y Dirección.'));
                exit;
            }

            $pdo->beginTransaction();

            if ($id === null) {
                // CREATE
                $sql = "INSERT INTO centros_salud (codigo_sicm, nombre, tipo_centro_id, medico_director, horario_atencion, direccion, telefono_principal, activo)
                        VALUES (:codigo, :nombre, :tipo_id, :director, :horario, :direccion, :telefono, 1)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':codigo' => $codigo,
                    ':nombre' => $nombre,
                    ':tipo_id' => $tipoId,
                    ':director' => $medicoDirector !== '' ? $medicoDirector : null,
                    ':horario' => $horarioAtencion !== '' ? $horarioAtencion : null,
                    ':direccion' => $direccion,
                    ':telefono' => $telefono !== '' ? $telefono : null
                ]);
                $nuevoId = (int) $pdo->lastInsertId();

                // Sincronizar Relación N:M
                if (!empty($recursos)) {
                    $stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:c, :r, 1)");
                    foreach ($recursos as $rId) {
                        $stmtRec->execute([':c' => $nuevoId, ':r' => $rId]);
                    }
                }

                registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_CENTRO', 'centros_salud', $nuevoId, [
                    'evento' => 'Registro de ambulatorio en la red municipal',
                    'codigo_sicm' => $codigo,
                    'nombre' => $nombre,
                    'recursos_ids' => $recursos
                ]);

                $pdo->commit();
                header('Location: index.php?tab=centros&msg=' . urlencode("Ambulatorio '{$nombre}' ({$codigo}) registrado exitosamente."));
                exit;

            } else {
                // UPDATE con Delta Forense
                $stmtPrev = $pdo->prepare("SELECT * FROM centros_salud WHERE id = :id");
                $stmtPrev->execute([':id' => $id]);
                $prev = $stmtPrev->fetch();

                if (!$prev) {
                    $pdo->rollBack();
                    header('Location: index.php?tab=centros&error=' . urlencode('El ambulatorio no existe.'));
                    exit;
                }

                $stmtRecPrev = $pdo->prepare("SELECT recurso_id FROM centro_recursos WHERE centro_salud_id = :id");
                $stmtRecPrev->execute([':id' => $id]);
                $recursosPrevios = array_map('intval', $stmtRecPrev->fetchAll(PDO::FETCH_COLUMN));

                $sql = "UPDATE centros_salud 
                        SET codigo_sicm = :codigo, nombre = :nombre, tipo_centro_id = :tipo_id, 
                            medico_director = :director, horario_atencion = :horario,
                            direccion = :direccion, telefono_principal = :telefono
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':codigo' => $codigo,
                    ':nombre' => $nombre,
                    ':tipo_id' => $tipoId,
                    ':director' => $medicoDirector !== '' ? $medicoDirector : null,
                    ':horario' => $horarioAtencion !== '' ? $horarioAtencion : null,
                    ':direccion' => $direccion,
                    ':telefono' => $telefono !== '' ? $telefono : null,
                    ':id' => $id
                ]);

                $pdo->prepare("DELETE FROM centro_recursos WHERE centro_salud_id = :id")->execute([':id' => $id]);
                if (!empty($recursos)) {
                    $stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:c, :r, 1)");
                    foreach ($recursos as $rId) {
                        $stmtRec->execute([':c' => $id, ':r' => $rId]);
                    }
                }

                // Cálculo de Campos Alterados
                $antes = [
                    'codigo_sicm' => $prev['codigo_sicm'],
                    'nombre' => $prev['nombre'],
                    'tipo_centro_id' => (int) $prev['tipo_centro_id'],
                    'medico_director' => $prev['medico_director'],
                    'horario' => $prev['horario_atencion'],
                    'direccion' => $prev['direccion'],
                    'telefono' => $prev['telefono_principal'],
                    'recursos_ids' => $recursosPrevios
                ];
                $despues = [
                    'codigo_sicm' => $codigo,
                    'nombre' => $nombre,
                    'tipo_centro_id' => $tipoId,
                    'medico_director' => $medicoDirector !== '' ? $medicoDirector : null,
                    'horario' => $horarioAtencion !== '' ? $horarioAtencion : null,
                    'direccion' => $direccion,
                    'telefono' => $telefono !== '' ? $telefono : null,
                    'recursos_ids' => $recursos
                ];

                $delta = [];
                foreach ($despues as $k => $v) {
                    if ($antes[$k] != $v) {
                        $delta[$k] = ['antes' => $antes[$k], 'despues' => $v];
                    }
                }

                registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_CENTRO', 'centros_salud', $id, [
                    'evento' => 'Actualización de ficha operativa de ambulatorio',
                    'nombre' => $nombre,
                    'codigo_sicm' => $codigo,
                    'campos_alterados' => array_keys($delta),
                    'delta' => $delta
                ]);

                $pdo->commit();
                header('Location: index.php?tab=centros&msg=' . urlencode("Ambulatorio #{$id} actualizado."));
                exit;
            }
            break;

        // ======================================================================
        // 2. SOFT DELETE / REACTIVACIÓN DE AMBULATORIO
        // ======================================================================
        case 'cambiar_estado_centro':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $nuevoEstado = isset($_GET['estado']) ? (int) $_GET['estado'] : null;

            if (!$id || ($nuevoEstado !== 0 && $nuevoEstado !== 1)) {
                header('Location: index.php?tab=centros&error=' . urlencode('Solicitud inválida.'));
                exit;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT codigo_sicm, nombre FROM centros_salud WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $centro = $stmt->fetch();

            if (!$centro) {
                $pdo->rollBack();
                header('Location: index.php?tab=centros&error=' . urlencode('Ambulatorio no encontrado.'));
                exit;
            }

            $pdo->prepare("UPDATE centros_salud SET activo = :st WHERE id = :id")->execute([':st' => $nuevoEstado, ':id' => $id]);

            $accionLog = ($nuevoEstado === 1) ? 'REACTIVAR_CENTRO' : 'DESACTIVAR_CENTRO';
            registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'centros_salud', $id, [
                'evento' => ($nuevoEstado === 1) ? 'Reactivación en red' : 'Soft Delete (activo=0)',
                'codigo_sicm' => $centro['codigo_sicm'],
                'nombre' => $centro['nombre']
            ]);

            $pdo->commit();
            $msg = ($nuevoEstado === 1)
                ? "Ambulatorio '{$centro['nombre']}' reactivado exitosamente."
                : "Ambulatorio '{$centro['nombre']}' desactivado lógicamente (Soft Delete).";
            header('Location: index.php?tab=centros&msg=' . urlencode($msg));
            exit;

        // ======================================================================
        // 3. CATÁLOGOS MAESTROS PARAMETRIZABLES (3FN)
        // ======================================================================
        case 'crear_tipo_centro':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=maestras');
                exit;
            }

            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $nombre = trim($_POST['nombre'] ?? '');
            $desc = trim($_POST['descripcion'] ?? '');

            if (mb_strlen($codigo, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') < 3) {
                header('Location: index.php?tab=maestras&error=' . urlencode('Código y Denominación son obligatorios.'));
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO tipos_centro_salud (codigo, nombre, descripcion) VALUES (:c, :n, :d)");
            $stmt->execute([':c' => $codigo, ':n' => $nombre, ':d' => $desc !== '' ? $desc : null]);
            $nuevoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_TIPO_CENTRO', 'tipos_centro_salud', $nuevoId, [
                'evento' => 'Nueva clasificación asistencial parametrizada en 3FN',
                'codigo' => $codigo,
                'nombre' => $nombre
            ]);

            header('Location: index.php?tab=maestras&msg=' . urlencode("Clasificación '{$nombre}' registrada en catálogo maestro."));
            exit;

        case 'toggle_tipo_centro':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $st = isset($_GET['estado']) ? (int) $_GET['estado'] : null;

            if (!$id || ($st !== 0 && $st !== 1)) {
                header('Location: index.php?tab=maestras&error=' . urlencode('Parámetros inválidos.'));
                exit;
            }

            $stmtT = $pdo->prepare("SELECT nombre, codigo FROM tipos_centro_salud WHERE id = :id");
            $stmtT->execute([':id' => $id]);
            $tipoInfo = $stmtT->fetch();
            $tipoNombre = $tipoInfo['nombre'] ?? "Tipo #{$id}";
            $tipoCodigo = $tipoInfo['codigo'] ?? '';

            $pdo->prepare("UPDATE tipos_centro_salud SET activo = :st WHERE id = :id")->execute([':st' => $st, ':id' => $id]);
            registrarAuditoria($pdo, $usuarioSesionId, 'CONMUTAR_TIPO_CENTRO', 'tipos_centro_salud', $id, [
                'evento' => 'Conmutación de vigencia de clasificación asistencial',
                'nombre' => $tipoNombre,
                'codigo' => $tipoCodigo,
                'nuevo_estado' => $st
            ]);

            header('Location: index.php?tab=maestras&msg=' . urlencode("Vigencia de clasificación '{$tipoNombre}' actualizada."));
            exit;

        case 'crear_recurso':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=maestras');
                exit;
            }

            $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
            $nombre = trim($_POST['nombre'] ?? '');
            $desc = trim($_POST['descripcion'] ?? '');

            if (mb_strlen($codigo, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') < 3) {
                header('Location: index.php?tab=maestras&error=' . urlencode('Código y Nombre del recurso son obligatorios.'));
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO recursos_operativos (codigo, nombre, descripcion, activo) VALUES (:c, :n, :d, 1)");
            $stmt->execute([':c' => $codigo, ':n' => $nombre, ':d' => $desc !== '' ? $desc : null]);
            $nuevoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_RECURSO', 'recursos_operativos', $nuevoId, [
                'evento' => 'Nueva capacidad/equipamiento añadido a catálogo N:M',
                'codigo' => $codigo,
                'nombre' => $nombre
            ]);

            header('Location: index.php?tab=maestras&msg=' . urlencode("Equipamiento '{$nombre}' añadido al catálogo."));
            exit;

        case 'toggle_recurso':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $st = isset($_GET['estado']) ? (int) $_GET['estado'] : null;

            if (!$id || ($st !== 0 && $st !== 1)) {
                header('Location: index.php?tab=maestras&error=' . urlencode('Parámetros inválidos.'));
                exit;
            }

            $stmtR = $pdo->prepare("SELECT nombre, codigo FROM recursos_operativos WHERE id = :id");
            $stmtR->execute([':id' => $id]);
            $recInfo = $stmtR->fetch();
            $recNombre = $recInfo['nombre'] ?? "Recurso #{$id}";
            $recCodigo = $recInfo['codigo'] ?? '';

            $pdo->prepare("UPDATE recursos_operativos SET activo = :st WHERE id = :id")->execute([':st' => $st, ':id' => $id]);
            registrarAuditoria($pdo, $usuarioSesionId, 'CONMUTAR_RECURSO', 'recursos_operativos', $id, [
                'evento' => 'Conmutación de disponibilidad de recurso',
                'nombre' => $recNombre,
                'codigo' => $recCodigo,
                'nuevo_estado' => $st
            ]);

            header('Location: index.php?tab=maestras&msg=' . urlencode('Disponibilidad de equipamiento actualizada.'));
            exit;

        // ======================================================================
        // 4. GESTIÓN DE OPERADORES Y SEGURIDAD RBAC (ADMINISTRADOR CENTRAL)
        // ======================================================================
        case 'guardar_usuario':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=usuarios');
                exit;
            }

            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $nombre = trim($_POST['nombre'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $pass = (string) ($_POST['password'] ?? '');
            $rolId = (int) ($_POST['rol_id'] ?? 0);
            $centroId = !empty($_POST['centro_salud_id']) ? (int) $_POST['centro_salud_id'] : null;

            // Regla de Negocio: Administrador tiene jurisdicción global (NULL).
            // Farmaceuta DEBE estar adscrito a un ambulatorio específico.
            if ($rolId === 1) {
                $centroId = null;
            } elseif ($rolId === 2) {
                if ($centroId === null || $centroId <= 0) {
                    header('Location: index.php?tab=usuarios&error=' . urlencode('El rol farmaceuta requiere obligatoriamente un ambulatorio de adscripción.'));
                    exit;
                }
            } else {
                header('Location: index.php?tab=usuarios&error=' . urlencode('El rol seleccionado no es válido.'));
                exit;
            }

            if (mb_strlen($nombre, 'UTF-8') < 4 || mb_strlen($nombre, 'UTF-8') > 80 || !preg_match('/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s\.\-]{4,80}$/u', $nombre)) {
                header('Location: index.php?tab=usuarios&error=' . urlencode('El nombre debe tener entre 4 y 80 caracteres (solo letras y espacios).'));
                exit;
            }
            if (mb_strlen($email, 'UTF-8') < 5 || mb_strlen($email, 'UTF-8') > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                header('Location: index.php?tab=usuarios&error=' . urlencode('Debe ingresar un correo institucional válido (ej. operador@salud.prueba.ve).'));
                exit;
            }

            $pdo->beginTransaction();

            if ($id === null) {
                // CREATE OPERADOR (Política de Seguridad: 8-72 chars, mayúscula, minúscula, número, símbolo)
                $passLen = strlen($pass);
                if ($passLen < 8 || $passLen > 72 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $pass)) {
                    $pdo->rollBack();
                    header('Location: index.php?tab=usuarios&error=' . urlencode('La contraseña inicial debe tener entre 8 y 72 caracteres e incluir mayúscula, minúscula, número y símbolo especial.'));
                    exit;
                }

                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO usuarios (nombre, email, password_hash, rol_id, centro_salud_id, activo)
                    VALUES (:nombre, :email, :pass, :rol, :centro, 1)
                ");
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':email' => $email,
                    ':pass' => $hash,
                    ':rol' => $rolId,
                    ':centro' => $centroId
                ]);
                $nuevoUsuarioId = (int) $pdo->lastInsertId();

                registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_USUARIO', 'usuarios', $nuevoUsuarioId, [
                    'evento' => 'Alta de operador en el padrón de funcionarios',
                    'nombre' => $nombre,
                    'email' => $email,
                    'rol_id' => $rolId,
                    'centro_salud_id' => $centroId
                ]);

                $pdo->commit();
                header('Location: index.php?tab=usuarios&msg=' . urlencode("Operador '{$nombre}' registrado exitosamente en el sistema."));
                exit;

            } else {
                // UPDATE OPERADOR con Delta Forense
                $stmtPrev = $pdo->prepare("SELECT id, nombre, email, rol_id, centro_salud_id FROM usuarios WHERE id = :id");
                $stmtPrev->execute([':id' => $id]);
                $prev = $stmtPrev->fetch();

                if (!$prev) {
                    $pdo->rollBack();
                    header('Location: index.php?tab=usuarios&error=' . urlencode('El operador especificado no existe.'));
                    exit;
                }

                $params = [
                    ':nombre' => $nombre,
                    ':email' => $email,
                    ':rol' => $rolId,
                    ':centro' => $centroId,
                    ':id' => $id
                ];

                $sqlPass = '';
                if ($pass !== '') {
                    $passLen = strlen($pass);
                    if ($passLen < 8 || $passLen > 72 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $pass)) {
                        $pdo->rollBack();
                        header('Location: index.php?tab=usuarios&error=' . urlencode('La nueva contraseña debe tener entre 8 y 72 caracteres e incluir mayúscula, minúscula, número y símbolo especial.'));
                        exit;
                    }
                    $sqlPass = ', password_hash = :pass';
                    $params[':pass'] = password_hash($pass, PASSWORD_DEFAULT);
                }

                $sql = "UPDATE usuarios 
                        SET nombre = :nombre, email = :email, rol_id = :rol, centro_salud_id = :centro {$sqlPass}
                        WHERE id = :id";
                $pdo->prepare($sql)->execute($params);

                $delta = [];
                if ($prev['nombre'] !== $nombre)
                    $delta['nombre'] = ['antes' => $prev['nombre'], 'despues' => $nombre];
                if ($prev['email'] !== $email)
                    $delta['email'] = ['antes' => $prev['email'], 'despues' => $email];
                if ((int) $prev['rol_id'] !== $rolId)
                    $delta['rol_id'] = ['antes' => (int) $prev['rol_id'], 'despues' => $rolId];
                if ($prev['centro_salud_id'] != $centroId)
                    $delta['centro_salud_id'] = ['antes' => $prev['centro_salud_id'], 'despues' => $centroId];
                if ($pass !== '')
                    $delta['password'] = ['antes' => '***', 'despues' => 'MODIFICADA'];

                registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_USUARIO', 'usuarios', $id, [
                    'evento' => 'Actualización de perfil de operador',
                    'nombre' => $nombre,
                    'campos_alterados' => array_keys($delta),
                    'delta' => $delta
                ]);

                $pdo->commit();
                header('Location: index.php?tab=usuarios&msg=' . urlencode("Perfil del operador #{$id} actualizado."));
                exit;
            }
            break;

        case 'cambiar_estado_usuario':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $nuevoEstado = isset($_GET['estado']) ? (int) $_GET['estado'] : null;

            if (!$id || ($nuevoEstado !== 0 && $nuevoEstado !== 1)) {
                header('Location: index.php?tab=usuarios&error=' . urlencode('Solicitud inválida.'));
                exit;
            }

            if ($id === $usuarioSesionId) {
                header('Location: index.php?tab=usuarios&error=' . urlencode('Por seguridad, no puede desactivar su propia cuenta activa.'));
                exit;
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $uRow = $stmt->fetch();

            if (!$uRow) {
                $pdo->rollBack();
                header('Location: index.php?tab=usuarios&error=' . urlencode('Operador no encontrado.'));
                exit;
            }

            $pdo->prepare("UPDATE usuarios SET activo = :st WHERE id = :id")->execute([':st' => $nuevoEstado, ':id' => $id]);

            $accionLog = ($nuevoEstado === 1) ? 'REACTIVAR_USUARIO' : 'DESACTIVAR_USUARIO';
            registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'usuarios', $id, [
                'evento' => ($nuevoEstado === 1) ? 'Reactivación de cuenta de operador' : 'Soft delete / Desactivación de cuenta de operador',
                'nombre' => $uRow['nombre'],
                'email' => $uRow['email']
            ]);

            $pdo->commit();
            $msg = ($nuevoEstado === 1)
                ? "Operador '{$uRow['nombre']}' reactivado exitosamente."
                : "Operador '{$uRow['nombre']}' desactivado lógicamente (Soft Delete).";
            header('Location: index.php?tab=usuarios&msg=' . urlencode($msg));
            exit;

        // ======================================================================
        // 5. DISASTER RECOVERY Y RESPALDOS (STREAMING EN O(1) Y AUDITORÍA WORM)
        // ======================================================================
        case 'descargar_respaldo':
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
            // Seguridad: MYSQL_PWD se transmite por entorno del subproceso (no visible en ps aux)
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

        default:
            header('Location: index.php');
            exit;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $targetTab = 'centros';
    if (str_contains($accion, 'tipo') || str_contains($accion, 'recurso')) {
        $targetTab = 'maestras';
    } elseif (str_contains($accion, 'usuario')) {
        $targetTab = 'usuarios';
    }

    $codigoSql  = (string)$e->getCode();
    $mensajeSql = $e->getMessage();
    $driverCode = (int)($e->errorInfo[1] ?? 0);

    // Mapeo detallado de violaciones de restricciones en el motor MariaDB
    if ($driverCode === 1062 || str_contains($mensajeSql, '1062 Duplicate entry')) {
        if (str_contains($mensajeSql, 'uk_retiro_mensual')) {
            $msg = 'Bloqueo Antifraude [MariaDB 1062]: El paciente ya registra un despacho en este periodo mensual. La restricción UNIQUE (uk_retiro_mensual) en la tabla despachos impidió el doble retiro.';
        } elseif (str_contains($mensajeSql, 'email')) {
            $msg = 'Restricción UNIQUE [MariaDB 1062]: El correo electrónico ya se encuentra registrado por otro operador en la tabla usuarios.';
        } elseif (str_contains($mensajeSql, 'codigo_sanitario') || str_contains($mensajeSql, 'codigo')) {
            $msg = 'Restricción UNIQUE [MariaDB 1062]: El código ingresado ya existe en la base de datos.';
        } else {
            $msg = "Restricción UNIQUE [MariaDB 1062 - Entrada Duplicada]: {$mensajeSql}";
        }
    } elseif ($driverCode === 1451 || str_contains($mensajeSql, '1451')) {
        $msg = 'Integridad Referencial [MariaDB 1451 - Restricción FK]: No es posible eliminar o alterar este registro porque existen filas vinculadas en tablas dependientes (lotes, usuarios o despachos).';
    } elseif ($driverCode === 1452 || str_contains($mensajeSql, '1452')) {
        $msg = 'Integridad Referencial [MariaDB 1452 - Restricción FK]: El registro hace referencia a una clave foránea (FK) que no existe en la tabla principal.';
    } elseif ($driverCode === 4025 || (str_contains($mensajeSql, 'CONSTRAINT') && str_contains($mensajeSql, 'failed'))) {
        $msg = "Restricción CHECK [MariaDB 4025]: Los datos no cumplen la regla de validación física a nivel de columna: {$mensajeSql}";
    } elseif ($codigoSql === '45000') {
        $msg = "Restricción Activa [Trigger SQLSTATE 45000]: {$mensajeSql}";
    } else {
        $msg = "Error de Base de Datos [SQLSTATE {$codigoSql} - Código {$driverCode}]: {$mensajeSql}";
    }

    header("Location: index.php?tab={$targetTab}&error=" . urlencode($msg));
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: index.php?error=' . urlencode('Error del sistema: ' . $e->getMessage()));
    exit;
}

