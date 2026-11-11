<?php
declare(strict_types=1);

/**
 * Action Handler: Gestión de Operadores y Usuarios
 * Sistema Integrado de Control de Medicamentos (SICM)
 */

if (!defined('SICM_CONTROLADOR')) {
    http_response_code(403);
    exit('Acceso denegado.');
}

switch ($accion) {
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

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        if ($id === null) {
            // CREATE OPERADOR (Política de Seguridad: 8-72 chars, mayúscula, minúscula, número, símbolo)
            $passLen = mb_strlen($pass, 'UTF-8');
            if ($passLen < 8 || $passLen > 72 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $pass)) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
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
                $passLen = mb_strlen($pass, 'UTF-8');
                if ($passLen < 8 || $passLen > 72 || !preg_match('/[A-Z]/', $pass) || !preg_match('/[a-z]/', $pass) || !preg_match('/[0-9]/', $pass) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $pass)) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
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

    case 'cambiar_estado_usuario':
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $nuevoEstado = isset($_POST['estado']) ? (int) $_POST['estado'] : (isset($_GET['estado']) ? (int) $_GET['estado'] : null);

        if (!$id || ($nuevoEstado !== 0 && $nuevoEstado !== 1)) {
            header('Location: index.php?tab=usuarios&error=' . urlencode('Solicitud inválida.'));
            exit;
        }

        if ($id === $usuarioSesionId) {
            header('Location: index.php?tab=usuarios&error=' . urlencode('Por seguridad, no puede desactivar su propia cuenta activa.'));
            exit;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        $stmt = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $uRow = $stmt->fetch();

        if (!$uRow) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

    default:
        header('Location: index.php?tab=usuarios');
        exit;
}
