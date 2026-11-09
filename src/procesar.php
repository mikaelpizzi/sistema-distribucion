<?php
// src/procesar.php
// Controlador Transaccional Centralizado (PHP Nativo / PDO - Cero ORM / Cero Frameworks)
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';
require_once __DIR__ . '/helpers/configuracion.php';

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
    'descargar_respaldo',
    'guardar_identidad_institucional',
    'crear_medicamento',
    'editar_medicamento',
    'cambiar_estado_medicamento',
    'crear_patologia',
    'cambiar_estado_patologia',
    'crear_forma_farmaceutica'
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


        case 'guardar_identidad_institucional':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=configuracion');
                exit;
            }

            $nombreSistema = trim($_POST['nombre_sistema'] ?? '');
            $siglasSistema = strtoupper(trim($_POST['siglas_sistema'] ?? ''));
            $enteRector    = trim($_POST['ente_rector'] ?? '');
            $municipio     = trim($_POST['municipio'] ?? '');
            $diasFefo      = (int)($_POST['dias_alerta_fefo'] ?? 60);
            $eliminarLogo  = !empty($_POST['eliminar_logo']);
            $logotipoRuta  = trim($_POST['logotipo_ruta'] ?? '');

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
                    'image/png'     => 'png',
                    'image/jpeg'    => 'jpg',
                    'image/svg+xml' => 'svg',
                    'image/webp'    => 'webp'
                ];

                if (!isset($permitidos[$mime])) {
                    header('Location: index.php?tab=configuracion&error=' . urlencode('Formato de imagen no permitido. Admite PNG, JPG, SVG y WEBP.'));
                    exit;
                }

                $uploadDir = __DIR__ . '/public/img';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }

                $ext = $permitidos[$mime];
                $destName = 'logo_institucional_' . time() . '.' . $ext;
                $destPath = $uploadDir . '/' . $destName;

                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $rutaFinalLogo = 'public/img/' . $destName;
                }
            } elseif (!empty($logotipoRuta)) {
                $rutaFinalLogo = $logotipoRuta;
            }

            $pdo->beginTransaction();

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
                ':nombre'    => $nombreSistema,
                ':siglas'    => $siglasSistema,
                ':ente'      => $enteRector,
                ':municipio' => $municipio,
                ':logo'      => $rutaFinalLogo,
                ':dias'      => $diasFefo
            ]);

            // Auditoría forense del cambio con delta detallado
            registrarAuditoria($pdo, $usuarioSesionId, 'ACTUALIZAR_IDENTIDAD_INSTITUCIONAL', 'configuracion_institucional', 1, [
                'evento' => 'Actualización de Identidad Institucional y Parametrización FEFO',
                'delta' => [
                    'nombre_sistema'   => ['antes' => $actual['nombre_sistema'], 'despues' => $nombreSistema],
                    'siglas_sistema'   => ['antes' => $actual['siglas_sistema'], 'despues' => $siglasSistema],
                    'ente_rector'      => ['antes' => $actual['ente_rector'], 'despues' => $enteRector],
                    'municipio'        => ['antes' => $actual['municipio'], 'despues' => $municipio],
                    'dias_alerta_fefo' => ['antes' => $actual['dias_alerta_fefo'], 'despues' => $diasFefo],
                    'logotipo_ruta'    => ['antes' => $actual['logotipo_ruta'], 'despues' => $rutaFinalLogo]
                ]
            ]);

            $pdo->commit();
            header('Location: index.php?tab=configuracion&msg=' . urlencode('Identidad institucional y parámetros actualizados exitosamente.'));
            exit;

        case 'crear_medicamento':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=medicamentos');
                exit;
            }

            $nombre = trim($_POST['nombre_generico'] ?? '');
            $concentracion = trim($_POST['concentracion'] ?? '');
            $formaFarmaceuticaId = (int) ($_POST['forma_farmaceutica_id'] ?? 0);
            $requiereFrio = !empty($_POST['requiere_cadena_frio']) ? 1 : 0;
            $stockMinimo = max(1, (int) ($_POST['stock_minimo_sugerido'] ?? 20));

            if (mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($concentracion, 'UTF-8') < 1 || $formaFarmaceuticaId <= 0) {
                header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Verifique los campos requeridos: Nombre Genérico, Concentración y Forma Farmacéutica.'));
                exit;
            }

            $stmtCheckF = $pdo->prepare("SELECT id, nombre FROM formas_farmaceuticas WHERE id = :id");
            $stmtCheckF->execute([':id' => $formaFarmaceuticaId]);
            $formaInfo = $stmtCheckF->fetch();
            if (!$formaInfo) {
                header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('La forma farmacéutica seleccionada no es válida.'));
                exit;
            }

            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare("
                INSERT INTO medicamentos (nombre_generico, concentracion, forma_farmaceutica_id, requiere_cadena_frio, stock_minimo_sugerido, activo)
                VALUES (:nombre, :conc, :forma_id, :frio, :stock, 1)
            ");
            $stmtIns->execute([
                ':nombre' => $nombre,
                ':conc' => $concentracion,
                ':forma_id' => $formaFarmaceuticaId,
                ':frio' => $requiereFrio,
                ':stock' => $stockMinimo
            ]);
            $nuevoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_MEDICAMENTO', 'medicamentos', $nuevoId, [
                'evento' => 'Alta de fármaco en Vademécum Institucional',
                'nombre_generico' => $nombre,
                'concentracion' => $concentracion,
                'forma_farmaceutica' => $formaInfo['nombre'],
                'requiere_cadena_frio' => $requiereFrio,
                'stock_minimo_sugerido' => $stockMinimo
            ]);

            $pdo->commit();
            header('Location: index.php?tab=medicamentos&subtab=vademecum&msg=' . urlencode("Medicamento '{$nombre} ({$concentracion})' registrado exitosamente en el vademécum municipal."));
            exit;

        case 'editar_medicamento':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=medicamentos');
                exit;
            }

            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $nombre = trim($_POST['nombre_generico'] ?? '');
            $concentracion = trim($_POST['concentracion'] ?? '');
            $formaFarmaceuticaId = (int) ($_POST['forma_farmaceutica_id'] ?? 0);
            $requiereFrio = !empty($_POST['requiere_cadena_frio']) ? 1 : 0;
            $stockMinimo = max(1, (int) ($_POST['stock_minimo_sugerido'] ?? 20));

            if (!$id || mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($concentracion, 'UTF-8') < 1 || $formaFarmaceuticaId <= 0) {
                header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Verifique los campos requeridos: ID, Nombre Genérico, Concentración y Forma Farmacéutica.'));
                exit;
            }

            $stmtOld = $pdo->prepare("SELECT * FROM medicamentos WHERE id = :id");
            $stmtOld->execute([':id' => $id]);
            $antiguo = $stmtOld->fetch();
            if (!$antiguo) {
                header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('El medicamento especificado no existe.'));
                exit;
            }

            $stmtCheckF = $pdo->prepare("SELECT id, nombre FROM formas_farmaceuticas WHERE id = :id");
            $stmtCheckF->execute([':id' => $formaFarmaceuticaId]);
            $formaInfo = $stmtCheckF->fetch();
            if (!$formaInfo) {
                header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('La forma farmacéutica seleccionada no es válida.'));
                exit;
            }

            $delta = [];
            if ($antiguo['nombre_generico'] !== $nombre) {
                $delta['nombre_generico'] = ['antes' => $antiguo['nombre_generico'], 'despues' => $nombre];
            }
            if ($antiguo['concentracion'] !== $concentracion) {
                $delta['concentracion'] = ['antes' => $antiguo['concentracion'], 'despues' => $concentracion];
            }
            if ((int) $antiguo['forma_farmaceutica_id'] !== $formaFarmaceuticaId) {
                $delta['forma_farmaceutica_id'] = ['antes' => (int) $antiguo['forma_farmaceutica_id'], 'despues' => $formaFarmaceuticaId];
            }
            if ((int) $antiguo['requiere_cadena_frio'] !== $requiereFrio) {
                $delta['requiere_cadena_frio'] = ['antes' => (int) $antiguo['requiere_cadena_frio'], 'despues' => $requiereFrio];
            }
            if ((int) $antiguo['stock_minimo_sugerido'] !== $stockMinimo) {
                $delta['stock_minimo_sugerido'] = ['antes' => (int) $antiguo['stock_minimo_sugerido'], 'despues' => $stockMinimo];
            }

            $pdo->beginTransaction();

            // La actualización dispara automáticamente el trigger trg_medicamentos_audit_delta
            $stmtUpd = $pdo->prepare("
                UPDATE medicamentos
                SET nombre_generico = :nombre,
                    concentracion = :conc,
                    forma_farmaceutica_id = :forma_id,
                    requiere_cadena_frio = :frio,
                    stock_minimo_sugerido = :stock
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':nombre' => $nombre,
                ':conc' => $concentracion,
                ':forma_id' => $formaFarmaceuticaId,
                ':frio' => $requiereFrio,
                ':stock' => $stockMinimo,
                ':id' => $id
            ]);

            registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_MEDICAMENTO', 'medicamentos', $id, [
                'evento' => 'Actualización de especificaciones de fármaco en Vademécum',
                'nombre_generico' => $nombre,
                'delta' => !empty($delta) ? $delta : 'Sin modificaciones detectadas'
            ]);

            $pdo->commit();
            header('Location: index.php?tab=medicamentos&subtab=vademecum&msg=' . urlencode("Medicamento '{$nombre}' actualizado correctamente."));
            exit;

        case 'cambiar_estado_medicamento':
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            }

            if (!$id) {
                header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Identificador de fármaco inválido.'));
                exit;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, nombre_generico, concentracion, activo FROM medicamentos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $med = $stmt->fetch();

            if (!$med) {
                $pdo->rollBack();
                header('Location: index.php?tab=medicamentos&subtab=vademecum&error=' . urlencode('Fármaco no encontrado.'));
                exit;
            }

            $nuevoEstado = ((int) $med['activo'] === 1) ? 0 : 1;
            $stmtUpd = $pdo->prepare("UPDATE medicamentos SET activo = :activo WHERE id = :id");
            $stmtUpd->execute([':activo' => $nuevoEstado, ':id' => $id]);

            $accionLog = ($nuevoEstado === 1) ? 'REACTIVAR_MEDICAMENTO' : 'DESACTIVAR_MEDICAMENTO';
            registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'medicamentos', $id, [
                'evento' => ($nuevoEstado === 1) ? 'Reactivación de fármaco en vademécum' : 'Descontinuación de fármaco (Soft Delete)',
                'nombre_generico' => $med['nombre_generico'],
                'concentracion' => $med['concentracion'],
                'estado_anterior' => (int) $med['activo'],
                'nuevo_estado' => $nuevoEstado
            ]);

            $pdo->commit();
            $msg = ($nuevoEstado === 1)
                ? "Fármaco '{$med['nombre_generico']}' reactivado exitosamente en el vademécum."
                : "Fármaco '{$med['nombre_generico']}' descontinuado del vademécum operativo.";
            header('Location: index.php?tab=medicamentos&subtab=vademecum&msg=' . urlencode($msg));
            exit;

        case 'crear_patologia':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=medicamentos&subtab=patologias');
                exit;
            }

            $codigo = strtoupper(trim($_POST['codigo_cie10'] ?? ''));
            $codigo = preg_replace('/[^A-Z0-9\.\-]/', '', $codigo);
            if ($codigo === '') {
                $codigo = null;
            }

            $nombre = trim($_POST['nombre'] ?? '');
            $cronica = !empty($_POST['cronica']) ? 1 : 0;
            $descripcion = trim($_POST['descripcion'] ?? '');

            if (mb_strlen($nombre, 'UTF-8') < 3) {
                header('Location: index.php?tab=medicamentos&subtab=patologias&error=' . urlencode('El diagnóstico/nombre de la patología es obligatorio (mínimo 3 caracteres).'));
                exit;
            }

            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare("
                INSERT INTO patologias (codigo_cie10, nombre, cronica, descripcion, activo)
                VALUES (:codigo, :nombre, :cronica, :descripcion, 1)
            ");
            $stmtIns->execute([
                ':codigo' => $codigo,
                ':nombre' => $nombre,
                ':cronica' => $cronica,
                ':descripcion' => $descripcion !== '' ? $descripcion : null
            ]);
            $nuevoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_PATOLOGIA', 'patologias', $nuevoId, [
                'evento' => 'Registro de patología diagnóstica en catálogo CIE-10',
                'codigo_cie10' => $codigo,
                'nombre' => $nombre,
                'cronica' => $cronica,
                'descripcion' => $descripcion !== '' ? $descripcion : null
            ]);

            $pdo->commit();
            header('Location: index.php?tab=medicamentos&subtab=patologias&msg=' . urlencode("Patología CIE-10 '{$nombre}' registrada satisfactoriamente."));
            exit;

        case 'cambiar_estado_patologia':
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            }

            if (!$id) {
                header('Location: index.php?tab=medicamentos&subtab=patologias&error=' . urlencode('Identificador de patología inválido.'));
                exit;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, codigo_cie10, nombre, activo FROM patologias WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $pat = $stmt->fetch();

            if (!$pat) {
                $pdo->rollBack();
                header('Location: index.php?tab=medicamentos&subtab=patologias&error=' . urlencode('Patología no encontrada.'));
                exit;
            }

            $nuevoEstado = ((int) $pat['activo'] === 1) ? 0 : 1;
            $stmtUpd = $pdo->prepare("UPDATE patologias SET activo = :activo WHERE id = :id");
            $stmtUpd->execute([':activo' => $nuevoEstado, ':id' => $id]);

            $accionLog = ($nuevoEstado === 1) ? 'REACTIVAR_PATOLOGIA' : 'DESACTIVAR_PATOLOGIA';
            registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'patologias', $id, [
                'evento' => ($nuevoEstado === 1) ? 'Reactivación de patología en catálogo clínico' : 'Desactivación lógica de patología',
                'codigo_cie10' => $pat['codigo_cie10'],
                'nombre' => $pat['nombre'],
                'nuevo_estado' => $nuevoEstado
            ]);

            $pdo->commit();
            $msg = ($nuevoEstado === 1)
                ? "Patología '{$pat['nombre']}' reactivada exitosamente."
                : "Patología '{$pat['nombre']}' desactivada del catálogo clínico.";
            header('Location: index.php?tab=medicamentos&subtab=patologias&msg=' . urlencode($msg));
            exit;

        case 'crear_forma_farmaceutica':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=medicamentos&subtab=formas');
                exit;
            }

            $nombre = trim($_POST['nombre'] ?? '');
            if (mb_strlen($nombre, 'UTF-8') < 2) {
                header('Location: index.php?tab=medicamentos&subtab=formas&error=' . urlencode('La denominación de la forma farmacéutica es obligatoria (mínimo 2 caracteres).'));
                exit;
            }

            $pdo->beginTransaction();

            $stmtIns = $pdo->prepare("INSERT INTO formas_farmaceuticas (nombre) VALUES (:nombre)");
            $stmtIns->execute([':nombre' => $nombre]);
            $nuevoId = (int) $pdo->lastInsertId();

            registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_FORMA_FARMACEUTICA', 'formas_farmaceuticas', $nuevoId, [
                'evento' => 'Alta de presentación farmacéutica en catálogo',
                'nombre' => $nombre
            ]);

            $pdo->commit();
            header('Location: index.php?tab=medicamentos&subtab=formas&msg=' . urlencode("Forma farmacéutica '{$nombre}' incorporada exitosamente."));
            exit;

        case 'guardar_paciente':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=pacientes');
                exit;
            }

            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $tipoDocumentoId = (int) ($_POST['tipo_documento_id'] ?? 0);
            $cedula = preg_replace('/[^0-9]/', '', (string) ($_POST['cedula'] ?? ''));
            $nombreCompleto = trim((string) ($_POST['nombre_completo'] ?? ''));
            $fechaNacimiento = trim((string) ($_POST['fecha_nacimiento'] ?? ''));
            $direccionResidencia = trim((string) ($_POST['direccion_residencia'] ?? ''));

            if ($tipoDocumentoId <= 0) {
                header('Location: index.php?tab=pacientes&error=' . urlencode('Debe seleccionar un tipo de documento de identidad válido.'));
                exit;
            }

            if (!preg_match('/^[0-9]{5,12}$/', $cedula)) {
                header('Location: index.php?tab=pacientes&error=' . urlencode('El número de cédula debe contener únicamente entre 5 y 12 dígitos numéricos sin puntos ni letras.'));
                exit;
            }

            if (mb_strlen($nombreCompleto, 'UTF-8') < 3) {
                header('Location: index.php?tab=pacientes&error=' . urlencode('El nombre y apellido del paciente debe contener al menos 3 caracteres.'));
                exit;
            }

            $fechaNacValida = null;
            if ($fechaNacimiento !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $fechaNacimiento);
                if (!$d || $d->format('Y-m-d') !== $fechaNacimiento) {
                    header('Location: index.php?tab=pacientes&error=' . urlencode('La fecha de nacimiento no tiene un formato válido (AAAA-MM-DD).'));
                    exit;
                }
                if ($fechaNacimiento > date('Y-m-d')) {
                    header('Location: index.php?tab=pacientes&error=' . urlencode('La fecha de nacimiento no puede ser una fecha futura.'));
                    exit;
                }
                $fechaNacValida = $fechaNacimiento;
            }

            // Validar que el tipo de documento exista en catálogo
            $stmtCheckDoc = $pdo->prepare("SELECT id FROM tipos_documento WHERE id = :id");
            $stmtCheckDoc->execute([':id' => $tipoDocumentoId]);
            if (!$stmtCheckDoc->fetch()) {
                header('Location: index.php?tab=pacientes&error=' . urlencode('El tipo de documento seleccionado no existe en el catálogo.'));
                exit;
            }

            // Catálogo de tipos de contacto válidos
            $tiposValidos = $pdo->query("SELECT id FROM tipos_contacto")->fetchAll(PDO::FETCH_COLUMN);
            $setTiposValidos = array_map('intval', $tiposValidos);
            $tipoDefecto = !empty($setTiposValidos) ? $setTiposValidos[0] : 2;

            // Procesar arreglo dinámico de contactos (1FN)
            $contactosTipo = (array) ($_POST['contactos_tipo'] ?? []);
            $contactosValor = (array) ($_POST['contactos_valor'] ?? []);
            $contactosDetalle = (array) ($_POST['contactos_detalle'] ?? []);
            $principalIdx = isset($_POST['contacto_principal_radio']) ? (int)$_POST['contacto_principal_radio'] : 0;

            $contactosAInsertar = [];
            $huboPrincipal = false;
            $totalFilas = max(count($contactosTipo), count($contactosValor), count($contactosDetalle));

            for ($i = 0; $i < $totalFilas; $i++) {
                $rawVal = isset($contactosValor[$i]) ? trim((string)$contactosValor[$i]) : '';
                if ($rawVal === '') {
                    continue; // Omitir filas vacías
                }

                $rawTipo = isset($contactosTipo[$i]) ? (int)$contactosTipo[$i] : $tipoDefecto;
                if (!in_array($rawTipo, $setTiposValidos, true)) {
                    $rawTipo = $tipoDefecto;
                }

                $rawDet = isset($contactosDetalle[$i]) ? trim((string)$contactosDetalle[$i]) : '';
                $esPrincipal = ($i === $principalIdx) ? 1 : 0;
                if ($esPrincipal === 1) {
                    $huboPrincipal = true;
                }

                $contactosAInsertar[] = [
                    'tipo_id'      => $rawTipo,
                    'valor'        => mb_substr($rawVal, 0, 120, 'UTF-8'),
                    'detalle'      => $rawDet !== '' ? mb_substr($rawDet, 0, 100, 'UTF-8') : null,
                    'es_principal' => $esPrincipal
                ];
            }

            // Compatibilidad hacia atrás si se envían los campos individuales tradicionales
            if (empty($contactosAInsertar) && (!empty($_POST['telefono_principal']) || !empty($_POST['contacto_emergencia_telefono']))) {
                if (!empty($_POST['telefono_principal'])) {
                    $contactosAInsertar[] = [
                        'tipo_id'      => (int)($_POST['tipo_contacto_principal_id'] ?? 2),
                        'valor'        => mb_substr(trim($_POST['telefono_principal']), 0, 120, 'UTF-8'),
                        'detalle'      => 'Paciente Directo',
                        'es_principal' => 1
                    ];
                    $huboPrincipal = true;
                }
                if (!empty($_POST['contacto_emergencia_telefono'])) {
                    $contactosAInsertar[] = [
                        'tipo_id'      => (int)($_POST['tipo_contacto_emergencia_id'] ?? 5),
                        'valor'        => mb_substr(trim($_POST['contacto_emergencia_telefono']), 0, 120, 'UTF-8'),
                        'detalle'      => trim($_POST['contacto_emergencia_nombre'] ?? '') ?: 'Contacto de Emergencia',
                        'es_principal' => empty($contactosAInsertar) ? 1 : 0
                    ];
                }
            }

            // Asegurar que al menos una vía quede marcada como principal
            if (!$huboPrincipal && !empty($contactosAInsertar)) {
                $contactosAInsertar[0]['es_principal'] = 1;
            }

            $pdo->beginTransaction();

            if ($id === null) {
                // CREATE
                $sql = "INSERT INTO pacientes (tipo_documento_id, cedula, nombre_completo, fecha_nacimiento, direccion_residencia)
                        VALUES (:tipo_doc, :cedula, :nombre, :fecha_nac, :direccion)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tipo_doc'   => $tipoDocumentoId,
                    ':cedula'     => $cedula,
                    ':nombre'     => $nombreCompleto,
                    ':fecha_nac'  => $fechaNacValida,
                    ':direccion'  => $direccionResidencia !== '' ? $direccionResidencia : null
                ]);
                $pacienteId = (int) $pdo->lastInsertId();

                // Sincronizar Contactos 1FN
                if (!empty($contactosAInsertar)) {
                    $stmtCont = $pdo->prepare("INSERT INTO paciente_contactos (paciente_id, tipo_contacto_id, valor, es_principal, nombre_contacto_emergencia)
                                               VALUES (:pid, :tid, :valor, :es_p, :detalle)");
                    foreach ($contactosAInsertar as $c) {
                        $stmtCont->execute([
                            ':pid'     => $pacienteId,
                            ':tid'     => $c['tipo_id'],
                            ':valor'   => $c['valor'],
                            ':es_p'    => $c['es_principal'],
                            ':detalle' => $c['detalle']
                        ]);
                    }
                }

                registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_PACIENTE', 'pacientes', $pacienteId, [
                    'evento'             => 'Registro de paciente en padrón municipal',
                    'cedula'             => $cedula,
                    'nombre_completo'    => $nombreCompleto,
                    'tipo_documento_id'  => $tipoDocumentoId,
                    'total_contactos'    => count($contactosAInsertar),
                    'contactos'          => array_map(function($c) { 
                        return "{$c['valor']} [" . ($c['es_principal'] ? 'Principal' : 'Secundario') . ($c['detalle'] ? " - {$c['detalle']}" : '') . "]"; 
                    }, $contactosAInsertar)
                ]);

                $pdo->commit();
                header('Location: index.php?tab=pacientes&msg=' . urlencode("Paciente '{$nombreCompleto}' registrado exitosamente en el padrón municipal."));
                exit;

            } else {
                // UPDATE
                $stmtPrev = $pdo->prepare("SELECT * FROM pacientes WHERE id = :id");
                $stmtPrev->execute([':id' => $id]);
                $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

                if (!$prev) {
                    $pdo->rollBack();
                    header('Location: index.php?tab=pacientes&error=' . urlencode('El paciente especificado no existe en el sistema.'));
                    exit;
                }

                $sql = "UPDATE pacientes
                        SET tipo_documento_id = :tipo_doc,
                            cedula = :cedula,
                            nombre_completo = :nombre,
                            fecha_nacimiento = :fecha_nac,
                            direccion_residencia = :direccion
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':tipo_doc'   => $tipoDocumentoId,
                    ':cedula'     => $cedula,
                    ':nombre'     => $nombreCompleto,
                    ':fecha_nac'  => $fechaNacValida,
                    ':direccion'  => $direccionResidencia !== '' ? $direccionResidencia : null,
                    ':id'         => $id
                ]);

                // Eliminar contactos previos del paciente e insertar la nueva colección (1FN Real con permiso DELETE)
                $stmtDel = $pdo->prepare("DELETE FROM paciente_contactos WHERE paciente_id = :pid");
                $stmtDel->execute([':pid' => $id]);

                if (!empty($contactosAInsertar)) {
                    $stmtCont = $pdo->prepare("INSERT INTO paciente_contactos (paciente_id, tipo_contacto_id, valor, es_principal, nombre_contacto_emergencia)
                                               VALUES (:pid, :tid, :valor, :es_p, :detalle)");
                    foreach ($contactosAInsertar as $c) {
                        $stmtCont->execute([
                            ':pid'     => $id,
                            ':tid'     => $c['tipo_id'],
                            ':valor'   => $c['valor'],
                            ':es_p'    => $c['es_principal'],
                            ':detalle' => $c['detalle']
                        ]);
                    }
                }

                registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_PACIENTE', 'pacientes', $id, [
                    'evento'             => 'Actualización de expediente clínico del paciente',
                    'cedula'             => $cedula,
                    'nombre_completo'    => $nombreCompleto,
                    'tipo_documento_id'  => $tipoDocumentoId,
                    'total_contactos'    => count($contactosAInsertar),
                    'contactos'          => array_map(function($c) { 
                        return "{$c['valor']} [" . ($c['es_principal'] ? 'Principal' : 'Secundario') . ($c['detalle'] ? " - {$c['detalle']}" : '') . "]"; 
                    }, $contactosAInsertar)
                ]);

                $pdo->commit();
                header('Location: index.php?tab=pacientes&msg=' . urlencode("Expediente del paciente '{$nombreCompleto}' actualizado exitosamente."));
                exit;
            }

        // ======================================================================
        // 13. GESTIÓN DE RÉCIPES MÉDICOS Y TRATAMIENTOS AUTORIZADOS (CLÍNICO)
        // ======================================================================
        case 'guardar_recipe':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php?tab=recipes');
                exit;
            }

            $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
            $pacienteId = (int) ($_POST['paciente_id'] ?? 0);
            $medicamentoId = (int) ($_POST['medicamento_id'] ?? 0);
            $patologiaId = (int) ($_POST['patologia_id'] ?? 0);
            $dosisMensual = (int) ($_POST['dosis_mensual'] ?? 0);
            $fechaEmision = trim((string) ($_POST['fecha_emision_recipe'] ?? ''));
            $fechaVencimiento = trim((string) ($_POST['fecha_vencimiento_recipe'] ?? ''));
            $medicoPrescriptor = trim((string) ($_POST['medico_prescriptor'] ?? ''));

            if ($pacienteId <= 0) {
                header('Location: index.php?tab=recipes&error=' . urlencode('Debe seleccionar un paciente registrado en el padrón.'));
                exit;
            }

            if ($medicamentoId <= 0) {
                header('Location: index.php?tab=recipes&error=' . urlencode('Debe seleccionar un medicamento del vademécum.'));
                exit;
            }

            if ($patologiaId <= 0) {
                header('Location: index.php?tab=recipes&error=' . urlencode('Debe seleccionar una patología CIE-10 válida.'));
                exit;
            }

            if ($dosisMensual <= 0) {
                header('Location: index.php?tab=recipes&error=' . urlencode('La dosis mensual autorizada debe ser mayor a cero.'));
                exit;
            }

            if (empty($fechaEmision) || !DateTime::createFromFormat('Y-m-d', $fechaEmision)) {
                header('Location: index.php?tab=recipes&error=' . urlencode('La fecha de emisión del récipe es inválida.'));
                exit;
            }

            if (empty($fechaVencimiento) || !DateTime::createFromFormat('Y-m-d', $fechaVencimiento)) {
                header('Location: index.php?tab=recipes&error=' . urlencode('La fecha de vencimiento del récipe es inválida.'));
                exit;
            }

            if ($fechaVencimiento < $fechaEmision) {
                header('Location: index.php?tab=recipes&error=' . urlencode('La fecha de vencimiento no puede ser anterior a la fecha de emisión del récipe.'));
                exit;
            }

            if (mb_strlen($medicoPrescriptor, 'UTF-8') < 3) {
                header('Location: index.php?tab=recipes&error=' . urlencode('El médico prescriptor y matrícula MPPS deben contener al menos 3 caracteres.'));
                exit;
            }

            $pdo->beginTransaction();

            // Validar paciente existente
            $stmtPac = $pdo->prepare("SELECT id, cedula, nombre_completo FROM pacientes WHERE id = :id");
            $stmtPac->execute([':id' => $pacienteId]);
            $paciente = $stmtPac->fetch(PDO::FETCH_ASSOC);
            if (!$paciente) {
                $pdo->rollBack();
                header('Location: index.php?tab=recipes&error=' . urlencode('El paciente seleccionado no existe en el sistema.'));
                exit;
            }

            // Validar medicamento existente y activo
            $stmtMed = $pdo->prepare("SELECT id, nombre_generico, concentracion FROM medicamentos WHERE id = :id AND activo = 1");
            $stmtMed->execute([':id' => $medicamentoId]);
            $med = $stmtMed->fetch(PDO::FETCH_ASSOC);
            if (!$med) {
                $pdo->rollBack();
                header('Location: index.php?tab=recipes&error=' . urlencode('El medicamento seleccionado no está disponible en el vademécum.'));
                exit;
            }

            // Validar patología existente y activa
            $stmtPat = $pdo->prepare("SELECT id, codigo_cie10, nombre FROM patologias WHERE id = :id AND activo = 1");
            $stmtPat->execute([':id' => $patologiaId]);
            $pat = $stmtPat->fetch(PDO::FETCH_ASSOC);
            if (!$pat) {
                $pdo->rollBack();
                header('Location: index.php?tab=recipes&error=' . urlencode('La patología CIE-10 seleccionada no se encuentra activa.'));
                exit;
            }

            if ($id === null) {
                // CREATE
                $sql = "INSERT INTO tratamientos_autorizados 
                        (paciente_id, medicamento_id, patologia_id, dosis_mensual, fecha_emision_recipe, fecha_vencimiento_recipe, medico_prescriptor, activo)
                        VALUES (:paciente_id, :medicamento_id, :patologia_id, :dosis_mensual, :fecha_emision, :fecha_vencimiento, :medico, 1)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':paciente_id'      => $pacienteId,
                    ':medicamento_id'   => $medicamentoId,
                    ':patologia_id'     => $patologiaId,
                    ':dosis_mensual'    => $dosisMensual,
                    ':fecha_emision'    => $fechaEmision,
                    ':fecha_vencimiento'=> $fechaVencimiento,
                    ':medico'           => $medicoPrescriptor
                ]);
                $nuevoId = (int) $pdo->lastInsertId();

                registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_RECIPE', 'tratamientos_autorizados', $nuevoId, [
                    'evento'             => 'Prescripción médica emitida y tratamiento autorizado registrado',
                    'paciente_id'        => $pacienteId,
                    'paciente'           => $paciente['nombre_completo'],
                    'cedula'             => $paciente['cedula'],
                    'medicamento_id'     => $medicamentoId,
                    'medicamento'        => $med['nombre_generico'] . ' ' . $med['concentracion'],
                    'patologia_id'       => $patologiaId,
                    'patologia'          => "[{$pat['codigo_cie10']}] {$pat['nombre']}",
                    'dosis_mensual'      => $dosisMensual,
                    'fecha_emision'      => $fechaEmision,
                    'fecha_vencimiento'  => $fechaVencimiento,
                    'medico_prescriptor' => $medicoPrescriptor
                ]);

                $pdo->commit();
                header('Location: index.php?tab=recipes&msg=' . urlencode("Récipe médico emitido exitosamente para '{$paciente['nombre_completo']}'."));
                exit;

            } else {
                // UPDATE
                $stmtPrev = $pdo->prepare("SELECT * FROM tratamientos_autorizados WHERE id = :id");
                $stmtPrev->execute([':id' => $id]);
                $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);

                if (!$prev) {
                    $pdo->rollBack();
                    header('Location: index.php?tab=recipes&error=' . urlencode('El récipe médico especificado no existe en el sistema.'));
                    exit;
                }

                $sql = "UPDATE tratamientos_autorizados 
                        SET paciente_id = :paciente_id,
                            medicamento_id = :medicamento_id,
                            patologia_id = :patologia_id,
                            dosis_mensual = :dosis_mensual,
                            fecha_emision_recipe = :fecha_emision,
                            fecha_vencimiento_recipe = :fecha_vencimiento,
                            medico_prescriptor = :medico
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':paciente_id'      => $pacienteId,
                    ':medicamento_id'   => $medicamentoId,
                    ':patologia_id'     => $patologiaId,
                    ':dosis_mensual'    => $dosisMensual,
                    ':fecha_emision'    => $fechaEmision,
                    ':fecha_vencimiento'=> $fechaVencimiento,
                    ':medico'           => $medicoPrescriptor,
                    ':id'               => $id
                ]);

                registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_RECIPE', 'tratamientos_autorizados', $id, [
                    'evento'             => 'Actualización de prescripción médica y tratamiento autorizado',
                    'paciente_id'        => $pacienteId,
                    'paciente'           => $paciente['nombre_completo'],
                    'medicamento_id'     => $medicamentoId,
                    'medicamento'        => $med['nombre_generico'] . ' ' . $med['concentracion'],
                    'patologia_id'       => $patologiaId,
                    'patologia'          => "[{$pat['codigo_cie10']}] {$pat['nombre']}",
                    'dosis_mensual'      => $dosisMensual,
                    'fecha_emision'      => $fechaEmision,
                    'fecha_vencimiento'  => $fechaVencimiento,
                    'medico_prescriptor' => $medicoPrescriptor
                ]);

                $pdo->commit();
                header('Location: index.php?tab=recipes&msg=' . urlencode("Récipe médico #{$id} actualizado exitosamente."));
                exit;
            }

        case 'toggle_estado_recipe':
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            }

            if (!$id) {
                header('Location: index.php?tab=recipes&error=' . urlencode('Identificador de récipe médico inválido.'));
                exit;
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT id, paciente_id, medicamento_id, activo FROM tratamientos_autorizados WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $rec = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$rec) {
                $pdo->rollBack();
                header('Location: index.php?tab=recipes&error=' . urlencode('Récipe médico no encontrado.'));
                exit;
            }

            $nuevoEstado = ((int) $rec['activo'] === 1) ? 0 : 1;
            $stmtUpd = $pdo->prepare("UPDATE tratamientos_autorizados SET activo = :activo WHERE id = :id");
            $stmtUpd->execute([':activo' => $nuevoEstado, ':id' => $id]);

            $accionLog = ($nuevoEstado === 1) ? 'ACTIVAR_RECIPE' : 'DESACTIVAR_RECIPE';
            registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'tratamientos_autorizados', $id, [
                'evento'          => ($nuevoEstado === 1) ? 'Reactivación de prescripción médica' : 'Suspensión/desactivación de prescripción médica',
                'paciente_id'     => $rec['paciente_id'],
                'medicamento_id'  => $rec['medicamento_id'],
                'estado_anterior' => (int) $rec['activo'],
                'nuevo_estado'    => $nuevoEstado
            ]);

            $pdo->commit();
            $msg = ($nuevoEstado === 1)
                ? "Récipe médico #{$id} reactivado exitosamente."
                : "Récipe médico #{$id} desactivado correctamente.";
            header('Location: index.php?tab=recipes&msg=' . urlencode($msg));
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
    } elseif (str_contains($accion, 'paciente')) {
        $targetTab = 'pacientes';
    } elseif (str_contains($accion, 'recipe')) {
        $targetTab = 'recipes';
    } elseif (str_contains($accion, 'configuracion') || str_contains($accion, 'identidad')) {
        $targetTab = 'configuracion';
    } elseif (str_contains($accion, 'medicamento')) {
        $targetTab = 'medicamentos&subtab=vademecum';
    } elseif (str_contains($accion, 'patologia')) {
        $targetTab = 'medicamentos&subtab=patologias';
    } elseif (str_contains($accion, 'forma')) {
        $targetTab = 'medicamentos&subtab=formas';
    }

    $codigoSql  = (string)$e->getCode();
    $mensajeSql = $e->getMessage();
    $driverCode = (int)($e->errorInfo[1] ?? 0);

    // Mapeo amigable de violaciones de integridad y reglas de base de datos
    if ($driverCode === 1062 || str_contains($mensajeSql, '1062 Duplicate entry')) {
        if (str_contains($mensajeSql, 'uk_retiro_mensual')) {
            $msg = 'El paciente ya registró un retiro de este tratamiento en el periodo mensual actual.';
        } elseif (str_contains($mensajeSql, 'email')) {
            $msg = 'El correo electrónico ya se encuentra registrado por otro usuario.';
        } elseif (str_contains($mensajeSql, 'codigo_sanitario') || str_contains($mensajeSql, 'codigo')) {
            $msg = 'El código ingresado ya se encuentra registrado en el sistema.';
        } else {
            $msg = 'Ya existe un registro con los datos ingresados.';
        }
    } elseif ($driverCode === 1451 || str_contains($mensajeSql, '1451')) {
        $msg = 'No es posible eliminar o modificar este registro porque tiene datos vinculados en el sistema.';
    } elseif ($driverCode === 1452 || str_contains($mensajeSql, '1452')) {
        $msg = 'El registro hace referencia a un elemento que no existe.';
    } elseif ($driverCode === 4025 || (str_contains($mensajeSql, 'CONSTRAINT') && str_contains($mensajeSql, 'failed'))) {
        $msg = 'Los datos ingresados no cumplen con las reglas requeridas.';
    } elseif ($codigoSql === '45000') {
        $msg = 'Operación rechazada por reglas de auditoría e integridad del sistema.';
    } else {
        $msg = 'Ocurrió un error al procesar la solicitud en la base de datos.';
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

