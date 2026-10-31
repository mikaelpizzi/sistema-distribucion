<?php
// src/centros/procesar.php
// Controlador backend en PHP puro para procesamiento de transacciones CRUD con Auditoría Forense y Catálogos N:M
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();
$accion = $_REQUEST['accion'] ?? 'guardar';

// Operador autenticado simulado en la intranet (Usuario 1: Administrador Central de Salud)
$usuarioSesionId = 1;

try {
    switch ($accion) {
        // ----------------------------------------------------------------------
        // 1. GUARDAR (CREATE / UPDATE)
        // ----------------------------------------------------------------------
        case 'guardar':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: index.php');
                exit;
            }

            $id              = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $codigo          = strtoupper(trim($_POST['codigo_sicm'] ?? ''));
            $nombre          = trim($_POST['nombre'] ?? '');
            $tipoId          = (int)($_POST['tipo_centro_id'] ?? 0);
            $medicoDirector  = trim($_POST['medico_director'] ?? '');
            $horarioAtencion = trim($_POST['horario_atencion'] ?? '');
            $direccion       = trim($_POST['direccion'] ?? '');
            $telefono        = trim($_POST['telefono_principal'] ?? '');
            
            // Array de recursos operativos seleccionados (N:M dinámico)
            $recursos = isset($_POST['recursos']) && is_array($_POST['recursos']) 
                ? array_map('intval', $_POST['recursos']) 
                : [];

            // Escudo en backend (Validaciones estrictas de integridad)
            if (mb_strlen($codigo, 'UTF-8') < 3 || mb_strlen($nombre, 'UTF-8') < 4 || $tipoId <= 0 || mb_strlen($direccion, 'UTF-8') < 8) {
                header('Location: index.php?error=' . urlencode('Error de validación en servidor: Código, nombre, clasificación y dirección son obligatorios.'));
                exit;
            }

            // Iniciar Transacción ACID
            $pdo->beginTransaction();

            if ($id === null) {
                // ==============================================================
                // A. CREAR CENTRO DE SALUD (CREATE)
                // ==============================================================
                $sql = "INSERT INTO centros_salud (codigo_sicm, nombre, tipo_centro_id, medico_director, horario_atencion, direccion, telefono_principal, activo)
                        VALUES (:codigo, :nombre, :tipo_id, :director, :horario, :direccion, :telefono, 1)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':codigo'    => $codigo,
                    ':nombre'    => $nombre,
                    ':tipo_id'   => $tipoId,
                    ':director'  => $medicoDirector !== '' ? $medicoDirector : null,
                    ':horario'   => $horarioAtencion !== '' ? $horarioAtencion : null,
                    ':direccion' => $direccion,
                    ':telefono'  => $telefono !== '' ? $telefono : null
                ]);
                $nuevoId = (int)$pdo->lastInsertId();

                // Sincronizar Recursos Operativos (N:M)
                if (!empty($recursos)) {
                    $stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:centro_id, :recurso_id, 1)");
                    foreach ($recursos as $recId) {
                        $stmtRec->execute([
                            ':centro_id'  => $nuevoId,
                            ':recurso_id' => $recId
                        ]);
                    }
                }

                // Registro Forense de Auditoría (Misión 2)
                $detallesLog = [
                    'evento' => 'Registro de nuevo centro en red ambulatoria',
                    'datos_creados' => [
                        'codigo_sicm'        => $codigo,
                        'nombre'             => $nombre,
                        'tipo_centro_id'     => $tipoId,
                        'medico_director'    => $medicoDirector !== '' ? $medicoDirector : null,
                        'horario_atencion'   => $horarioAtencion !== '' ? $horarioAtencion : null,
                        'direccion'          => $direccion,
                        'telefono_principal' => $telefono !== '' ? $telefono : null,
                        'recursos_ids'       => $recursos
                    ]
                ];
                registrarAuditoria($pdo, $usuarioSesionId, 'CREAR_CENTRO', 'centros_salud', $nuevoId, $detallesLog);

                $pdo->commit();
                header('Location: index.php?msg=' . urlencode("Centro de salud #{$nuevoId} ({$codigo}) registrado exitosamente con sus capacidades operativas."));
                exit;

            } else {
                // ==============================================================
                // B. ACTUALIZAR CENTRO EXISTENTE (UPDATE CON DELTA DE AUDITORÍA)
                // ==============================================================
                // 1. Obtener estado previo del registro
                $stmtPrevio = $pdo->prepare("SELECT * FROM centros_salud WHERE id = :id");
                $stmtPrevio->execute([':id' => $id]);
                $estadoPrevio = $stmtPrevio->fetch();

                if (!$estadoPrevio) {
                    $pdo->rollBack();
                    header('Location: index.php?error=' . urlencode('El centro de salud especificado no existe.'));
                    exit;
                }

                // 2. Obtener recursos operativos previos
                $stmtRecPrevios = $pdo->prepare("SELECT recurso_id FROM centro_recursos WHERE centro_salud_id = :id");
                $stmtRecPrevios->execute([':id' => $id]);
                $recursosPrevios = $stmtRecPrevios->fetchAll(PDO::FETCH_COLUMN);
                $recursosPrevios = array_map('intval', $recursosPrevios);

                // 3. Ejecutar UPDATE en centros_salud
                $sql = "UPDATE centros_salud 
                        SET codigo_sicm = :codigo, nombre = :nombre, tipo_centro_id = :tipo_id, 
                            medico_director = :director, horario_atencion = :horario,
                            direccion = :direccion, telefono_principal = :telefono
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':codigo'    => $codigo,
                    ':nombre'    => $nombre,
                    ':tipo_id'   => $tipoId,
                    ':director'  => $medicoDirector !== '' ? $medicoDirector : null,
                    ':horario'   => $horarioAtencion !== '' ? $horarioAtencion : null,
                    ':direccion' => $direccion,
                    ':telefono'  => $telefono !== '' ? $telefono : null,
                    ':id'        => $id
                ]);

                // 4. Sincronizar Recursos Operativos en centro_recursos (N:M)
                $pdo->prepare("DELETE FROM centro_recursos WHERE centro_salud_id = :id")->execute([':id' => $id]);
                if (!empty($recursos)) {
                    $stmtRec = $pdo->prepare("INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo) VALUES (:centro_id, :recurso_id, 1)");
                    foreach ($recursos as $recId) {
                        $stmtRec->execute([
                            ':centro_id'  => $id,
                            ':recurso_id' => $recId
                        ]);
                    }
                }

                // 5. Calcular Delta y Registrar Auditoría Forense (Misión 2)
                $antes = [
                    'codigo_sicm'        => $estadoPrevio['codigo_sicm'],
                    'nombre'             => $estadoPrevio['nombre'],
                    'tipo_centro_id'     => (int)$estadoPrevio['tipo_centro_id'],
                    'medico_director'    => $estadoPrevio['medico_director'],
                    'horario_atencion'   => $estadoPrevio['horario_atencion'],
                    'direccion'          => $estadoPrevio['direccion'],
                    'telefono_principal' => $estadoPrevio['telefono_principal'],
                    'recursos_ids'       => $recursosPrevios
                ];

                $despues = [
                    'codigo_sicm'        => $codigo,
                    'nombre'             => $nombre,
                    'tipo_centro_id'     => $tipoId,
                    'medico_director'    => $medicoDirector !== '' ? $medicoDirector : null,
                    'horario_atencion'   => $horarioAtencion !== '' ? $horarioAtencion : null,
                    'direccion'          => $direccion,
                    'telefono_principal' => $telefono !== '' ? $telefono : null,
                    'recursos_ids'       => $recursos
                ];

                // Identificar campos específicos alterados
                $camposAlterados = [];
                foreach ($despues as $k => $v) {
                    if ($antes[$k] != $v) {
                        $camposAlterados[$k] = [
                            'antes'   => $antes[$k],
                            'despues' => $v
                        ];
                    }
                }

                $detallesLog = [
                    'evento'            => 'Actualización de ficha y capacidades del ambulatorio',
                    'campos_alterados'  => array_keys($camposAlterados),
                    'antes'             => $antes,
                    'despues'           => $despues,
                    'delta_especifico'  => $camposAlterados
                ];

                registrarAuditoria($pdo, $usuarioSesionId, 'EDITAR_CENTRO', 'centros_salud', $id, $detallesLog);

                $pdo->commit();
                header('Location: index.php?msg=' . urlencode("Centro de salud #{$id} actualizado correctamente con trazabilidad forense."));
                exit;
            }
            break;

        // ----------------------------------------------------------------------
        // 2. SOFT DELETE (CAMBIAR ESTADO ACTIVO / INACTIVO)
        // ----------------------------------------------------------------------
        case 'cambiar_estado':
            $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
            $nuevoEstado = isset($_GET['estado']) ? (int)$_GET['estado'] : null;

            if (!$id || ($nuevoEstado !== 0 && $nuevoEstado !== 1)) {
                header('Location: index.php?error=' . urlencode('Solicitud de cambio de estado inválida.'));
                exit;
            }

            $pdo->beginTransaction();

            // Consultar datos actuales del centro
            $stmt = $pdo->prepare("SELECT codigo_sicm, nombre, activo FROM centros_salud WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $centro = $stmt->fetch();

            if (!$centro) {
                $pdo->rollBack();
                header('Location: index.php?error=' . urlencode('El centro indicado no existe en la base de datos.'));
                exit;
            }

            // Política de integridad: Conmutar activo = 0/1 sin pérdida de datos históricos
            $stmtUpdate = $pdo->prepare("UPDATE centros_salud SET activo = :estado WHERE id = :id");
            $stmtUpdate->execute([
                ':estado' => $nuevoEstado,
                ':id'     => $id
            ]);

            $accionLog = ($nuevoEstado === 1) ? 'REACTIVAR_CENTRO' : 'DESACTIVAR_CENTRO';
            $detallesLog = [
                'evento'          => ($nuevoEstado === 1) ? 'Reactivación de ambulatorio en red activa' : 'Desactivación lógica (Soft Delete) de ambulatorio',
                'codigo_sicm'     => $centro['codigo_sicm'],
                'nombre'          => $centro['nombre'],
                'estado_anterior' => (int)$centro['activo'],
                'nuevo_estado'    => $nuevoEstado,
                'politica'        => 'Preservación de integridad referencial histórica (3FN)'
            ];

            registrarAuditoria($pdo, $usuarioSesionId, $accionLog, 'centros_salud', $id, $detallesLog);

            $pdo->commit();

            $mensaje = ($nuevoEstado === 1) 
                ? "Ambulatorio '{$centro['nombre']}' reactivado exitosamente en la red municipal." 
                : "Ambulatorio '{$centro['nombre']}' desactivado mediante borrado lógico (Soft Delete, activo = 0).";

            header('Location: index.php?msg=' . urlencode($mensaje));
            exit;
            break;

        default:
            header('Location: index.php');
            exit;
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Manejo de restricciones de integridad de motor MariaDB (ej: Código SICM duplicado)
    if ($e->getCode() === '23000') {
        $errorMsg = "Error de Integridad (Código 23000): Ya existe un centro registrado con el Código Sanitario o restricción foránea violada.";
    } else {
        $errorMsg = "Fallo de Base de Datos: " . $e->getMessage();
    }
    header('Location: index.php?error=' . urlencode($errorMsg));
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: index.php?error=' . urlencode('Error del sistema: ' . $e->getMessage()));
    exit;
}
