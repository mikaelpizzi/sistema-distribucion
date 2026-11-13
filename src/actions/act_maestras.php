<?php
// src/actions/act_maestras.php
// SICM | Action Handler: Catálogos Maestros, Matriz RBAC, Formas y Patologías
declare(strict_types=1);

switch ($accion) {
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
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $st = isset($_POST['estado']) ? (int) $_POST['estado'] : (isset($_GET['estado']) ? (int) $_GET['estado'] : null);

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
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $st = isset($_POST['estado']) ? (int) $_POST['estado'] : (isset($_GET['estado']) ? (int) $_GET['estado'] : null);

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

    case 'crear_rol':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=maestras');
            exit;
        }

        $rawNombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');

        // Normalización del nombre técnico del rol: 'rol_' + slug limpio
        $rawNombre = strtolower($rawNombre);
        if (str_starts_with($rawNombre, 'rol_')) {
            $rawNombre = substr($rawNombre, 4);
        }
        $rawNombre = preg_replace('/[^a-z0-9_]/', '', $rawNombre);
        $nombreRol = 'rol_' . $rawNombre;

        if (strlen($rawNombre) < 2 || mb_strlen($descripcion, 'UTF-8') < 3) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Identificador de rol (mín. 2 caracteres tras rol_) y Descripción institucional son obligatorios.'));
            exit;
        }

        // Validar unicidad en la base de datos
        $stmtCheck = $pdo->prepare("SELECT id FROM roles WHERE nombre = :n");
        $stmtCheck->execute([':n' => $nombreRol]);
        if ($stmtCheck->fetch()) {
            header('Location: index.php?tab=maestras&error=' . urlencode("El rol '{$nombreRol}' ya se encuentra registrado en el sistema."));
            exit;
        }

        $permisosSeleccionados = $_POST['permisos'] ?? [];
        if (!is_array($permisosSeleccionados)) {
            $permisosSeleccionados = [];
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        $stmtInsRol = $pdo->prepare("INSERT INTO roles (nombre, es_personalizado, descripcion) VALUES (:n, 1, :d)");
        $stmtInsRol->execute([':n' => $nombreRol, ':d' => $descripcion]);
        $nuevoRolId = (int) $pdo->lastInsertId();

        $totalAsignados = 0;
        if (!empty($permisosSeleccionados)) {
            $stmtInsPerm = $pdo->prepare("INSERT INTO rol_permisos (rol_id, permiso_id) VALUES (:r, :p)");
            foreach ($permisosSeleccionados as $pId) {
                $pId = (int) $pId;
                if ($pId > 0) {
                    $stmtInsPerm->execute([':r' => $nuevoRolId, ':p' => $pId]);
                    $totalAsignados++;
                }
            }
        }
        $pdo->commit();

        registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_ROL_RBAC', 'roles', $nuevoRolId, [
            'evento' => 'Creación de nuevo rol institucional parametrizable en 3FN',
            'nombre' => $nombreRol,
            'descripcion' => $descripcion,
            'total_permisos_asignados' => $totalAsignados
        ]);

        header('Location: index.php?tab=maestras&msg=' . urlencode("Rol personalizado '{$nombreRol}' registrado exitosamente con {$totalAsignados} permiso(s) asignado(s)."));
        exit;

    case 'actualizar_rol_permisos':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=maestras');
            exit;
        }

        $rolId = filter_input(INPUT_POST, 'rol_id', FILTER_VALIDATE_INT);
        $descripcion = trim($_POST['descripcion'] ?? '');

        if (!$rolId || mb_strlen($descripcion, 'UTF-8') < 3) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Identificador de rol y Descripción institucional son requeridos.'));
            exit;
        }

        $stmtR = $pdo->prepare("SELECT id, nombre, es_personalizado, descripcion FROM roles WHERE id = :id");
        $stmtR->execute([':id' => $rolId]);
        $rol = $stmtR->fetch();

        if (!$rol) {
            header('Location: index.php?tab=maestras&error=' . urlencode('El rol solicitado no existe.'));
            exit;
        }

        $permisosSeleccionados = $_POST['permisos'] ?? [];
        if (!is_array($permisosSeleccionados)) {
            $permisosSeleccionados = [];
        }

        // Blindaje de seguridad para rol_admin (id=1): nunca debe quedar sin permisos de administración
        if ((int) $rolId === 1 && empty($permisosSeleccionados)) {
            $todosPermisos = $pdo->query("SELECT id FROM permisos")->fetchAll(PDO::FETCH_COLUMN);
            $permisosSeleccionados = $todosPermisos;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        // Actualizar descripción
        $stmtUpd = $pdo->prepare("UPDATE roles SET descripcion = :d WHERE id = :id");
        $stmtUpd->execute([':d' => $descripcion, ':id' => $rolId]);

        // Reemplazar asignación atómica de permisos
        $stmtDel = $pdo->prepare("DELETE FROM rol_permisos WHERE rol_id = :id");
        $stmtDel->execute([':id' => $rolId]);

        $totalAsignados = 0;
        if (!empty($permisosSeleccionados)) {
            $stmtIns = $pdo->prepare("INSERT INTO rol_permisos (rol_id, permiso_id) VALUES (:r, :p)");
            foreach ($permisosSeleccionados as $pId) {
                $pId = (int) $pId;
                if ($pId > 0) {
                    $stmtIns->execute([':r' => $rolId, ':p' => $pId]);
                    $totalAsignados++;
                }
            }
        }
        $pdo->commit();

        // Si el usuario activo pertenece a este rol, refrescar de inmediato sus permisos en sesión
        if ((int) $usuario['rol_id'] === (int) $rolId) {
            $_SESSION['usuario_permisos'] = cargarPermisosRol($pdo, (int) $rolId);
        }

        registrarAuditoria($pdo, $usuarioSesionId, 'ACTUALIZAR_ROL_PERMISOS', 'roles', $rolId, [
            'evento' => 'Actualización de matriz RBAC y alcance funcional de rol',
            'nombre' => $rol['nombre'],
            'descripcion' => $descripcion,
            'total_permisos_asignados' => $totalAsignados
        ]);

        header('Location: index.php?tab=maestras&msg=' . urlencode("Matriz de permisos de '{$rol['nombre']}' sincronizada exitosamente ({$totalAsignados} permisos asignados)."));
        exit;

    case 'eliminar_rol':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=maestras');
            exit;
        }

        $rolId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if (!$rolId) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Identificador de rol no válido.'));
            exit;
        }

        $stmtR = $pdo->prepare("SELECT id, nombre, es_personalizado FROM roles WHERE id = :id");
        $stmtR->execute([':id' => $rolId]);
        $rol = $stmtR->fetch();

        if (!$rol) {
            header('Location: index.php?tab=maestras&error=' . urlencode('El rol solicitado no existe.'));
            exit;
        }

        if ((int) $rol['es_personalizado'] !== 1) {
            header('Location: index.php?tab=maestras&error=' . urlencode("Los roles base del sistema ('{$rol['nombre']}') forman parte del núcleo y no pueden ser eliminados."));
            exit;
        }

        // Validar que no tenga usuarios asignados
        $stmtU = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol_id = :id");
        $stmtU->execute([':id' => $rolId]);
        $numUsuarios = (int) $stmtU->fetchColumn();

        if ($numUsuarios > 0) {
            header('Location: index.php?tab=maestras&error=' . urlencode("No se puede eliminar el rol '{$rol['nombre']}' porque tiene {$numUsuarios} operador(es) asignado(s). Reasigne primero a los funcionarios."));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        $pdo->prepare("DELETE FROM rol_permisos WHERE rol_id = :id")->execute([':id' => $rolId]);
        $pdo->prepare("DELETE FROM roles WHERE id = :id")->execute([':id' => $rolId]);
        $pdo->commit();

        registrarAuditoria($pdo, $usuarioSesionId, 'ELIMINAR_ROL_RBAC', 'roles', $rolId, [
            'evento' => 'Eliminación definitiva de rol personalizado sin operadores activos',
            'nombre' => $rol['nombre']
        ]);

        header('Location: index.php?tab=maestras&msg=' . urlencode("Rol personalizado '{$rol['nombre']}' eliminado exitosamente."));
        exit;

    case 'crear_patologia':
    case 'guardar_patologia':
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
        $clasificacionId = filter_input(INPUT_POST, 'clasificacion_id', FILTER_VALIDATE_INT) ?: null;
        $descripcion = trim($_POST['descripcion'] ?? '');

        if ($clasificacionId) {
            $stmtClas = $pdo->prepare("SELECT es_recurrente_mensual FROM clasificaciones_patologia WHERE id = :id");
            $stmtClas->execute([':id' => $clasificacionId]);
            $rowClas = $stmtClas->fetch(PDO::FETCH_ASSOC);
            $cronica = $rowClas ? (int) $rowClas['es_recurrente_mensual'] : (!empty($_POST['cronica']) ? 1 : 0);
        } else {
            $cronica = !empty($_POST['cronica']) ? 1 : 0;
        }

        if (mb_strlen($nombre, 'UTF-8') < 3) {
            header('Location: index.php?tab=medicamentos&subtab=patologias&error=' . urlencode('El diagnóstico/nombre de la patología es obligatorio (mínimo 3 caracteres).'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO patologias (codigo_cie10, nombre, clasificacion_id, cronica, descripcion, activo)
            VALUES (:codigo, :nombre, :clas_id, :cronica, :descripcion, 1)
        ");
        $stmtIns->execute([
            ':codigo' => $codigo,
            ':nombre' => $nombre,
            ':clas_id' => $clasificacionId,
            ':cronica' => $cronica,
            ':descripcion' => $descripcion !== '' ? $descripcion : null
        ]);
        $nuevoId = (int) $pdo->lastInsertId();

        registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_PATOLOGIA', 'patologias', $nuevoId, [
            'evento' => 'Registro de patología diagnóstica en catálogo CIE-10',
            'codigo_cie10' => $codigo,
            'nombre' => $nombre,
            'clasificacion_id' => $clasificacionId,
            'cronica' => $cronica,
            'descripcion' => $descripcion !== '' ? $descripcion : null
        ]);

        $pdo->commit();
        header('Location: index.php?tab=medicamentos&subtab=patologias&msg=' . urlencode("Patología CIE-10 '{$nombre}' registrada satisfactoriamente."));
        exit;

    case 'cambiar_estado_patologia':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            header('Location: index.php?tab=medicamentos&subtab=patologias&error=' . urlencode('Identificador de patología inválido.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare("SELECT id, codigo_cie10, nombre, activo FROM patologias WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $pat = $stmt->fetch();

        if (!$pat) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
    case 'guardar_forma':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=medicamentos&subtab=formas');
            exit;
        }

        $nombre = trim($_POST['nombre'] ?? '');
        if (mb_strlen($nombre, 'UTF-8') < 2) {
            header('Location: index.php?tab=medicamentos&subtab=formas&error=' . urlencode('La denominación de la forma farmacéutica es obligatoria (mínimo 2 caracteres).'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

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

    // =========================================================================
    // 3. TIPOS DE DOCUMENTO DE IDENTIDAD (CATÁLOGO MAESTRO)
    // =========================================================================
    case 'crear_tipo_documento':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=maestras');
            exit;
        }

        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');

        if (mb_strlen($codigo, 'UTF-8') < 1 || mb_strlen($codigo, 'UTF-8') > 10 || mb_strlen($nombre, 'UTF-8') < 2) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Código (1-10 caracteres) y Nombre (mínimo 2 caracteres) son obligatorios.'));
            exit;
        }

        // Validar duplicidad
        $stmtCheck = $pdo->prepare("SELECT id FROM tipos_documento WHERE codigo = :c");
        $stmtCheck->execute([':c' => $codigo]);
        if ($stmtCheck->fetch()) {
            header('Location: index.php?tab=maestras&error=' . urlencode("El código de documento '{$codigo}' ya se encuentra registrado."));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmtIns = $pdo->prepare("INSERT INTO tipos_documento (codigo, nombre, activo) VALUES (:c, :n, 1)");
        $stmtIns->execute([':c' => $codigo, ':n' => $nombre]);
        $nuevoId = (int) $pdo->lastInsertId();

        registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_TIPO_DOCUMENTO', 'tipos_documento', $nuevoId, [
            'evento' => 'Alta de nuevo tipo de documento de identidad oficial',
            'codigo' => $codigo,
            'nombre' => $nombre
        ]);

        $pdo->commit();
        header('Location: index.php?tab=maestras&msg=' . urlencode("Tipo de documento '{$codigo} - {$nombre}' creado exitosamente."));
        exit;

    case 'editar_tipo_documento':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=maestras');
            exit;
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');

        if (!$id || mb_strlen($codigo, 'UTF-8') < 1 || mb_strlen($codigo, 'UTF-8') > 10 || mb_strlen($nombre, 'UTF-8') < 2) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Parámetros inválidos para edición de tipo de documento.'));
            exit;
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM tipos_documento WHERE codigo = :c AND id != :id");
        $stmtCheck->execute([':c' => $codigo, ':id' => $id]);
        if ($stmtCheck->fetch()) {
            header('Location: index.php?tab=maestras&error=' . urlencode("El código de documento '{$codigo}' ya está en uso por otro registro."));
            exit;
        }

        $stmtPrev = $pdo->prepare("SELECT codigo, nombre FROM tipos_documento WHERE id = :id");
        $stmtPrev->execute([':id' => $id]);
        $prevDoc = $stmtPrev->fetch(PDO::FETCH_ASSOC);

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmtUpd = $pdo->prepare("UPDATE tipos_documento SET codigo = :c, nombre = :n WHERE id = :id");
        $stmtUpd->execute([':c' => $codigo, ':n' => $nombre, ':id' => $id]);

        registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_TIPO_DOCUMENTO', 'tipos_documento', $id, [
            'evento' => 'Modificación de especificaciones de tipo de documento',
            'anterior' => $prevDoc,
            'nuevo' => ['codigo' => $codigo, 'nombre' => $nombre]
        ]);

        $pdo->commit();
        header('Location: index.php?tab=maestras&msg=' . urlencode("Tipo de documento '{$codigo}' actualizado con éxito."));
        exit;

    case 'toggle_tipo_documento':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $st = isset($_POST['estado']) ? (int) $_POST['estado'] : (isset($_GET['estado']) ? (int) $_GET['estado'] : null);

        if (!$id || ($st !== 0 && $st !== 1)) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Parámetros inválidos para conmutar estado.'));
            exit;
        }

        $stmtD = $pdo->prepare("SELECT codigo, nombre FROM tipos_documento WHERE id = :id");
        $stmtD->execute([':id' => $id]);
        $docInfo = $stmtD->fetch(PDO::FETCH_ASSOC);
        $docNombre = $docInfo['nombre'] ?? "Documento #{$id}";
        $docCodigo = $docInfo['codigo'] ?? '';

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $pdo->prepare("UPDATE tipos_documento SET activo = :st WHERE id = :id")->execute([':st' => $st, ':id' => $id]);

        registrarAuditoria($pdo, $usuarioSesionId, 'CONMUTAR_TIPO_DOCUMENTO', 'tipos_documento', $id, [
            'evento' => 'Conmutación de vigencia de tipo de documento',
            'codigo' => $docCodigo,
            'nombre' => $docNombre,
            'nuevo_estado' => $st
        ]);

        $pdo->commit();
        header('Location: index.php?tab=maestras&msg=' . urlencode("Estado de documento '{$docCodigo}' actualizado."));
        exit;

    // =========================================================================
    // 4. ATRIBUTOS FARMACOLÓGICOS (3FN - N:M)
    // =========================================================================
    case 'crear_atributo_farmacologico':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=maestras');
            exit;
        }

        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $codigo = preg_replace('/[^A-Z0-9_]/', '', $codigo);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $icono = trim($_POST['icono'] ?? 'bi-tag');
        $colorBadge = trim($_POST['color_badge'] ?? 'primary');

        if (mb_strlen($codigo, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') < 2) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Código (mínimo 2 caracteres) y Denominación son obligatorios.'));
            exit;
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM atributos_farmacologicos WHERE codigo = :c");
        $stmtCheck->execute([':c' => $codigo]);
        if ($stmtCheck->fetch()) {
            header('Location: index.php?tab=maestras&error=' . urlencode("El atributo '{$codigo}' ya se encuentra registrado."));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO atributos_farmacologicos (codigo, nombre, descripcion, icono, color_badge, activo)
            VALUES (:c, :n, :d, :ico, :color, 1)
        ");
        $stmtIns->execute([
            ':c' => $codigo,
            ':n' => $nombre,
            ':d' => $descripcion !== '' ? $descripcion : null,
            ':ico' => $icono !== '' ? $icono : 'bi-tag',
            ':color' => $colorBadge !== '' ? $colorBadge : 'primary'
        ]);
        $nuevoId = (int) $pdo->lastInsertId();

        registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_ATRIBUTO_FARMACOLOGICO', 'atributos_farmacologicos', $nuevoId, [
            'evento' => 'Alta de atributo farmacológico en catálogo 3FN',
            'codigo' => $codigo,
            'nombre' => $nombre,
            'color_badge' => $colorBadge
        ]);

        $pdo->commit();
        header('Location: index.php?tab=maestras&msg=' . urlencode("Atributo farmacológico '{$nombre}' incorporado exitosamente."));
        exit;

    case 'toggle_atributo_farmacologico':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $st = isset($_POST['estado']) ? (int) $_POST['estado'] : (isset($_GET['estado']) ? (int) $_GET['estado'] : null);

        if (!$id || ($st !== 0 && $st !== 1)) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Parámetros inválidos.'));
            exit;
        }

        $stmtA = $pdo->prepare("SELECT codigo, nombre FROM atributos_farmacologicos WHERE id = :id");
        $stmtA->execute([':id' => $id]);
        $atrInfo = $stmtA->fetch(PDO::FETCH_ASSOC);
        $atrNombre = $atrInfo['nombre'] ?? "Atributo #{$id}";

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $pdo->prepare("UPDATE atributos_farmacologicos SET activo = :st WHERE id = :id")->execute([':st' => $st, ':id' => $id]);

        registrarAuditoria($pdo, $usuarioSesionId, 'CONMUTAR_ATRIBUTO_FARMACOLOGICO', 'atributos_farmacologicos', $id, [
            'evento' => 'Conmutación de vigencia de atributo farmacológico',
            'nombre' => $atrNombre,
            'nuevo_estado' => $st
        ]);

        $pdo->commit();
        header('Location: index.php?tab=maestras&msg=' . urlencode("Estado de atributo '{$atrNombre}' actualizado."));
        exit;

    // =========================================================================
    // 5. CLASIFICACIONES DE PATOLOGÍAS (3FN)
    // =========================================================================
    case 'crear_clasificacion_patologia':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?tab=maestras');
            exit;
        }

        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $codigo = preg_replace('/[^A-Z0-9_]/', '', $codigo);
        $nombre = trim($_POST['nombre'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $esRecurrente = !empty($_POST['es_recurrente_mensual']) ? 1 : 0;

        if (mb_strlen($codigo, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') < 2) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Código (mínimo 2 caracteres) y Denominación son obligatorios.'));
            exit;
        }

        $stmtCheck = $pdo->prepare("SELECT id FROM clasificaciones_patologia WHERE codigo = :c");
        $stmtCheck->execute([':c' => $codigo]);
        if ($stmtCheck->fetch()) {
            header('Location: index.php?tab=maestras&error=' . urlencode("La clasificación '{$codigo}' ya se encuentra registrada."));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO clasificaciones_patologia (codigo, nombre, descripcion, es_recurrente_mensual, activo)
            VALUES (:c, :n, :d, :rec, 1)
        ");
        $stmtIns->execute([
            ':c' => $codigo,
            ':n' => $nombre,
            ':d' => $descripcion !== '' ? $descripcion : null,
            ':rec' => $esRecurrente
        ]);
        $nuevoId = (int) $pdo->lastInsertId();

        registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_CLASIFICACION_PATOLOGIA', 'clasificaciones_patologia', $nuevoId, [
            'evento' => 'Alta de clasificación nosológica de patología en 3FN',
            'codigo' => $codigo,
            'nombre' => $nombre,
            'es_recurrente_mensual' => $esRecurrente
        ]);

        $pdo->commit();
        header('Location: index.php?tab=maestras&msg=' . urlencode("Clasificación '{$nombre}' incorporada exitosamente."));
        exit;

    case 'toggle_clasificacion_patologia':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $st = isset($_POST['estado']) ? (int) $_POST['estado'] : (isset($_GET['estado']) ? (int) $_GET['estado'] : null);

        if (!$id || ($st !== 0 && $st !== 1)) {
            header('Location: index.php?tab=maestras&error=' . urlencode('Parámetros inválidos.'));
            exit;
        }

        $stmtCp = $pdo->prepare("SELECT codigo, nombre FROM clasificaciones_patologia WHERE id = :id");
        $stmtCp->execute([':id' => $id]);
        $cpInfo = $stmtCp->fetch(PDO::FETCH_ASSOC);
        $cpNombre = $cpInfo['nombre'] ?? "Clasificación #{$id}";

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $pdo->prepare("UPDATE clasificaciones_patologia SET activo = :st WHERE id = :id")->execute([':st' => $st, ':id' => $id]);

        registrarAuditoria($pdo, $usuarioSesionId, 'CONMUTAR_CLASIFICACION_PATOLOGIA', 'clasificaciones_patologia', $id, [
            'evento' => 'Conmutación de vigencia de clasificación de patología',
            'nombre' => $cpNombre,
            'nuevo_estado' => $st
        ]);

        $pdo->commit();
        header('Location: index.php?tab=maestras&msg=' . urlencode("Estado de clasificación '{$cpNombre}' actualizado."));
        exit;

    default:
        header('Location: index.php?tab=maestras');
        exit;
}
