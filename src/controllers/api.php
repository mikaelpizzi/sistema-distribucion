<?php
// src/controllers/api.php
// Controlador backend en PHP puro para el CRUD de medicamentos

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getDB();
    $accion = $_GET['accion'] ?? '';

    switch ($accion) {
        // 1. Obtener las formas farmacéuticas para llenar el <select> (Relación 3FN)
        case 'formas':
            $stmt = $pdo->query("SELECT id, nombre, unidad_base FROM formas_farmaceuticas WHERE deleted_at IS NULL ORDER BY nombre ASC");
            echo json_encode(['ok' => true, 'datos' => $stmt->fetchAll()]);
            break;

        // 2. READ: Paginación Server-Side (LIMIT / OFFSET) + Búsqueda por Prefijo B-Tree (Clase 14/10)
        case 'listar':
            $estado = ($_GET['estado'] ?? 'activos') === 'papelera' ? 'papelera' : 'activos';
            $condicion = ($estado === 'papelera') ? "deleted_at IS NOT NULL" : "deleted_at IS NULL";
            
            $busqueda = trim($_GET['q'] ?? '');
            $pagina = max(1, (int)($_GET['pagina'] ?? 1));
            $porPagina = 10; // Límite constante de registros por página
            $offset = ($pagina - 1) * $porPagina;

            $params = [];
            if ($busqueda !== '') {
                // Prefijo B-Tree (Clase 14/10): Comodín solo al final para evitar Full Scan en páginas de 16KB
                $condicion .= " AND (codigo_atc LIKE :q_atc OR nombre_generico LIKE :q_nom)";
                $params[':q_atc'] = $busqueda . '%';
                $params[':q_nom'] = $busqueda . '%';
            }

            // Asegurar que la vista exista
            $verificarVista = function() use ($pdo) {
                $pdo->exec("CREATE OR REPLACE VIEW vw_catalogo_medicamentos AS
                            SELECT m.id, m.codigo_atc, m.nombre_generico, m.concentracion,
                                   m.forma_farmaceutica_id, f.nombre AS forma_nombre, f.unidad_base,
                                   m.requiere_cadena_frio, m.deleted_at
                            FROM medicamentos m
                            INNER JOIN formas_farmaceuticas f ON f.id = m.forma_farmaceutica_id");
            };

            // 1. Conteo total de coincidencias para telemetría y cálculo de páginas
            try {
                $sqlCount = "SELECT COUNT(*) AS total FROM vw_catalogo_medicamentos WHERE {$condicion}";
                $stmtCount = $pdo->prepare($sqlCount);
                $stmtCount->execute($params);
                $totalRegistros = (int)$stmtCount->fetchColumn();
            } catch (PDOException $e) {
                $verificarVista();
                $stmtCount = $pdo->prepare($sqlCount);
                $stmtCount->execute($params);
                $totalRegistros = (int)$stmtCount->fetchColumn();
            }

            $totalPaginas = max(1, (int)ceil($totalRegistros / $porPagina));
            if ($pagina > $totalPaginas) {
                $pagina = $totalPaginas;
                $offset = ($pagina - 1) * $porPagina;
            }

            // 2. Consulta de datos acotada por LIMIT y OFFSET
            $sqlDatos = "SELECT id, codigo_atc, nombre_generico, concentracion, 
                                forma_farmaceutica_id, forma_nombre, requiere_cadena_frio, deleted_at
                         FROM vw_catalogo_medicamentos
                         WHERE {$condicion}
                         ORDER BY id DESC
                         LIMIT {$porPagina} OFFSET {$offset}";

            $stmtDatos = $pdo->prepare($sqlDatos);
            $stmtDatos->execute($params);
            $datos = $stmtDatos->fetchAll();

            echo json_encode([
                'ok' => true,
                'datos' => $datos,
                'paginacion' => [
                    'pagina_actual' => $pagina,
                    'total_paginas' => $totalPaginas,
                    'total_registros' => $totalRegistros,
                    'por_pagina' => $porPagina
                ]
            ]);
            break;

        // 3. CREATE / UPDATE: Guardar medicamento usando Prepared Statements contra Inyección SQL
        case 'guardar':
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
            $codigoAtc = strtoupper(trim($_POST['codigo_atc'] ?? ''));
            $nombre = trim($_POST['nombre_generico'] ?? '');
            
            // Soporte para concentración combinada (Valor + Unidad) o directa
            $valConc = trim($_POST['concentracion_valor'] ?? '');
            $uniConc = trim($_POST['concentracion_unidad'] ?? '');
            $concentracion = ($valConc !== '' && $uniConc !== '') ? ($valConc . $uniConc) : trim($_POST['concentracion'] ?? '');

            $formaId = (int)($_POST['forma_farmaceutica_id'] ?? 0);
            $frio = isset($_POST['requiere_cadena_frio']) ? 1 : 0;

            if ($codigoAtc === '' || $nombre === '' || $concentracion === '' || $formaId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Todos los campos obligatorios deben ser completados.']);
                exit;
            }

            if ($id === null) {
                // Inserción (CREATE)
                $sql = "INSERT INTO medicamentos (codigo_atc, nombre_generico, concentracion, forma_farmaceutica_id, requiere_cadena_frio)
                        VALUES (:atc, :nombre, :conc, :forma, :frio)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':atc'    => $codigoAtc,
                    ':nombre' => $nombre,
                    ':conc'   => $concentracion,
                    ':forma'  => $formaId,
                    ':frio'   => $frio
                ]);
                echo json_encode(['ok' => true, 'mensaje' => 'Medicamento registrado con éxito en el catálogo.']);
            } else {
                // Actualización (UPDATE)
                $sql = "UPDATE medicamentos 
                        SET codigo_atc = :atc, nombre_generico = :nombre, concentracion = :conc,
                            forma_farmaceutica_id = :forma, requiere_cadena_frio = :frio
                        WHERE id = :id AND deleted_at IS NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':atc'    => $codigoAtc,
                    ':nombre' => $nombre,
                    ':conc'   => $concentracion,
                    ':forma'  => $formaId,
                    ':frio'   => $frio,
                    ':id'     => $id
                ]);
                echo json_encode(['ok' => true, 'mensaje' => 'Medicamento actualizado correctamente.']);
            }
            break;

        // 4. DELETE: Borrado Lógico (Soft Delete)
        case 'eliminar':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Identificador no válido.']);
                exit;
            }

            $sql = "UPDATE medicamentos SET deleted_at = NOW() WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'mensaje' => 'Medicamento desincorporado. Enviado a la papelera (Soft Delete).']);
            break;

        // 5. RESTAURAR: Recuperar registro desincorporado
        case 'restaurar':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Identificador no válido.']);
                exit;
            }

            $sql = "UPDATE medicamentos SET deleted_at = NULL WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'mensaje' => 'Medicamento restaurado exitosamente al catálogo activo.']);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => 'Acción no permitida.']);
            break;
    }

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        echo json_encode(['ok' => false, 'error' => 'Error de Integridad: Ya existe un medicamento registrado con ese Código ATC.']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Error de Base de Datos: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Error de Servidor: ' . $e->getMessage()]);
}
