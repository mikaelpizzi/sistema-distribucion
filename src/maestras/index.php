<?php
// src/maestras/index.php
// Panel de Configuración de Tablas Maestras y Catálogos Parametrizables
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$tabActiva = $_GET['tab'] ?? 'recursos';
$mensajeExito = $_GET['msg'] ?? null;
$mensajeError = $_GET['error'] ?? null;

// 1. Consulta de Tipos de Centro con conteo de centros asociados
$sqlTipos = "SELECT t.id, t.codigo, t.nombre, t.descripcion, t.created_at, COUNT(c.id) AS total_centros
             FROM tipos_centro_salud t
             LEFT JOIN centros_salud c ON c.tipo_centro_id = t.id
             GROUP BY t.id, t.codigo, t.nombre, t.descripcion, t.created_at
             ORDER BY t.id ASC";
$tiposCentro = $pdo->query($sqlTipos)->fetchAll();

// 2. Consulta de Recursos Operativos con conteo de centros asignados
$sqlRecursos = "SELECT r.id, r.codigo, r.nombre, r.descripcion, r.activo, r.created_at, COUNT(cr.centro_salud_id) AS total_asignados
                FROM recursos_operativos r
                LEFT JOIN centro_recursos cr ON cr.recurso_id = r.id
                GROUP BY r.id, r.codigo, r.nombre, r.descripcion, r.activo, r.created_at
                ORDER BY r.id ASC";
$recursosOperativos = $pdo->query($sqlRecursos)->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SICM | Tablas Maestras Parametrizables</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f3f4f6;
            font-size: 0.86rem;
            color: #1f2937;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .header-slim {
            background-color: #0f172a;
            border-bottom: 2px solid #3b82f6;
        }

        .card {
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.07);
        }

        .nav-link-tech {
            color: #94a3b8;
            font-size: 0.82rem;
            text-decoration: none;
            padding: 0.25rem 0.65rem;
            border-radius: 4px;
        }

        .nav-link-tech:hover {
            color: #ffffff;
            background-color: #1e293b;
        }

        .nav-link-tech.active {
            color: #38bdf8;
            background-color: #1e293b;
            font-weight: 600;
        }

        .table > :not(caption) > * > * {
            padding: 0.48rem 0.65rem;
        }
    </style>
</head>

<body>

    <!-- Header Técnico de Navegación Unificada -->
    <header class="header-slim text-white py-2 px-3 d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold tracking-wide text-info fs-6">SICM-RED</span>
            <span class="text-white-50">|</span>
            <nav class="d-flex gap-1">
                <a href="../centros/index.php" class="nav-link-tech">🏢 Red de Centros</a>
                <a href="index.php" class="nav-link-tech active">⚙️ Tablas Maestras</a>
                <a href="../auditoria/index.php" class="nav-link-tech">🛡️ Auditoría Forense</a>
            </nav>
        </div>
        <div class="text-white-50 font-monospace small" style="font-size: 0.76rem;">
            Módulo: <span class="text-white">Catálogos Paramétricos 3FN</span> | Motor: <span class="text-white">MariaDB InnoDB</span>
        </div>
    </header>

    <div class="container-fluid px-3 py-3">

        <!-- Mensajes de Feedback -->
        <?php if ($mensajeExito): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 shadow-sm mb-3" role="alert">
                <strong>Operación Exitosa:</strong> <?= htmlspecialchars($mensajeExito) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mensajeError): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm mb-3" role="alert">
                <strong>Fallo de Integridad:</strong> <?= htmlspecialchars($mensajeError) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Banner de Justificación de Negocio / Adaptabilidad Empresarial -->
        <div class="alert alert-light border shadow-sm py-2 px-3 mb-3 d-flex align-items-center gap-3">
            <div class="fs-4 text-primary">💡</div>
            <div class="small">
                <strong>Principio de Adaptabilidad Empresarial:</strong> Este panel demuestra cómo el software puede adaptarse a <em>cualquier modelo de negocio u organización</em> (redes de salud públicas, cadenas privadas de clínicas, consorcios hospitalarios o empresas logísticas de distribución). El Administrador define nuevas clasificaciones y capacidades operativas <strong>en caliente</strong>, sin programar código PHP ni alterar la estructura física de la base de datos (cero <code>ALTER TABLE</code>).
            </div>
        </div>

        <!-- Selector de Pestañas de Catálogo Maestro -->
        <ul class="nav nav-tabs mb-3" id="maestrasTab" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $tabActiva === 'recursos' ? 'active fw-bold' : '' ?>" href="index.php?tab=recursos">
                    ⚙️ Capacidades y Recursos Operativos (N:M) <span class="badge bg-secondary"><?= count($recursosOperativos) ?></span>
                </a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?= $tabActiva === 'tipos' ? 'active fw-bold' : '' ?>" href="index.php?tab=tipos">
                    🏥 Clasificación de Sedes y Centros (1:N) <span class="badge bg-secondary"><?= count($tiposCentro) ?></span>
                </a>
            </li>
        </ul>

        <?php if ($tabActiva === 'recursos'): ?>
            <!-- ================================================================ -->
            <!-- PESTAÑA 1: RECURSOS Y CAPACIDADES OPERATIVAS (N:M)              -->
            <!-- ================================================================ -->
            <div class="row g-3">
                <!-- FORMULARIO: NUEVO RECURSO -->
                <div class="col-xl-4 col-lg-5">
                    <div class="card border">
                        <div class="card-header bg-white text-dark fw-bold border-bottom py-2 px-3">
                            ➕ Parametrizar Nueva Capacidad Operativa
                        </div>
                        <div class="card-body p-3">
                            <form action="procesar.php" method="POST">
                                <input type="hidden" name="accion" value="crear_recurso">

                                <div class="mb-2">
                                    <label for="codigo_rec" class="form-label small fw-semibold mb-1">Código Nemotécnico Único</label>
                                    <input type="text" class="form-control form-control-sm font-monospace text-uppercase" id="codigo_rec" name="codigo" 
                                           placeholder="Ej: CAVA_FRIO_IND, HELIPUERTO, TOMOGRAFO" required maxlength="30">
                                    <div class="form-text" style="font-size: 0.72rem;">Identificador en mayúsculas sin espacios (3FN).</div>
                                </div>

                                <div class="mb-2">
                                    <label for="nombre_rec" class="form-label small fw-semibold mb-1">Nombre Descriptivo</label>
                                    <input type="text" class="form-control form-control-sm" id="nombre_rec" name="nombre" 
                                           placeholder="Ej: Cava Refrigerada Industrial (-20°C)" required maxlength="80">
                                </div>

                                <div class="mb-3">
                                    <label for="desc_rec" class="form-label small fw-semibold mb-1">Especificación Técnica / Alcance</label>
                                    <textarea class="form-control form-control-sm" id="desc_rec" name="descripcion" rows="3" 
                                              placeholder="Descripción operativa del equipamiento o certificación..." maxlength="255"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                    Registrar Capacidad en Catálogo Maestro
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- TABLA: LISTA DE RECURSOS OPERATIVOS -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card border">
                        <div class="card-header bg-white fw-bold py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                            <span>Catálogo Maestro de Recursos: <code>recursos_operativos</code></span>
                            <span class="badge bg-primary rounded-pill"><?= count($recursosOperativos) ?> capacidades</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead class="table-light text-muted small text-uppercase" style="font-size: 0.73rem;">
                                        <tr>
                                            <th style="width: 50px;">ID</th>
                                            <th style="width: 140px;">Código</th>
                                            <th>Capacidad / Equipamiento</th>
                                            <th>Especificación</th>
                                            <th style="width: 90px;" class="text-center">Centros</th>
                                            <th style="width: 90px;">Estado</th>
                                            <th style="width: 100px;" class="text-end pe-3">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recursosOperativos as $r): ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= (int)$r['id'] ?></td>
                                                <td><span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($r['codigo']) ?></span></td>
                                                <td class="fw-semibold text-dark"><?= htmlspecialchars($r['nombre']) ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars($r['descripcion'] ?? '—') ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-secondary rounded-pill"><?= (int)$r['total_asignados'] ?></span>
                                                </td>
                                                <td>
                                                    <?php if ((int)$r['activo'] === 1): ?>
                                                        <span class="badge bg-success">Activo</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Inactivo</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <?php if ((int)$r['activo'] === 1): ?>
                                                        <a href="procesar.php?accion=toggle_recurso&id=<?= (int)$r['id'] ?>&estado=0" 
                                                           class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.74rem;"
                                                           onclick="return confirm('¿Inactivar este recurso del catálogo maestro?')">Inactivar</a>
                                                    <?php else: ?>
                                                        <a href="procesar.php?accion=toggle_recurso&id=<?= (int)$r['id'] ?>&estado=1" 
                                                           class="btn btn-outline-success btn-sm py-0 px-2" style="font-size: 0.74rem;">Activar</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer bg-light py-2 px-3 text-muted small border-top">
                            Al agregar o activar un recurso aquí, <strong>se refleja de inmediato</strong> como un nuevo checkbox disponible en la ficha de ambulatorios.
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ================================================================ -->
            <!-- PESTAÑA 2: TIPOS Y CLASIFICACIÓN DE SEDES (tipos_centro_salud)   -->
            <!-- ================================================================ -->
            <div class="row g-3">
                <!-- FORMULARIO: NUEVO TIPO DE CENTRO -->
                <div class="col-xl-4 col-lg-5">
                    <div class="card border">
                        <div class="card-header bg-white text-dark fw-bold border-bottom py-2 px-3">
                            ➕ Registrar Nueva Clasificación de Sede
                        </div>
                        <div class="card-body p-3">
                            <form action="procesar.php" method="POST">
                                <input type="hidden" name="accion" value="crear_tipo_centro">

                                <div class="mb-2">
                                    <label for="codigo_tipo" class="form-label small fw-semibold mb-1">Código Sanitario / Jerárquico</label>
                                    <input type="text" class="form-control form-control-sm font-monospace text-uppercase" id="codigo_tipo" name="codigo" 
                                           placeholder="Ej: HOSP_GEN, CEDI_FARMA, CLIN_ESP" required maxlength="30">
                                </div>

                                <div class="mb-2">
                                    <label for="nombre_tipo" class="form-label small fw-semibold mb-1">Denominación Oficial</label>
                                    <input type="text" class="form-control form-control-sm" id="nombre_tipo" name="nombre" 
                                           placeholder="Ej: Hospital General / Centro de Distribución" required maxlength="80">
                                </div>

                                <div class="mb-3">
                                    <label for="desc_tipo" class="form-label small fw-semibold mb-1">Alcance Asistencial u Operativo</label>
                                    <textarea class="form-control form-control-sm" id="desc_tipo" name="descripcion" rows="3" 
                                              placeholder="Resolución médica, camas de hospitalización, horario..." maxlength="255"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                                    Registrar Clasificación en Catálogo Maestro
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- TABLA: LISTA DE TIPOS DE CENTRO -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card border">
                        <div class="card-header bg-white fw-bold py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                            <span>Catálogo Maestro de Clasificación: <code>tipos_centro_salud</code></span>
                            <span class="badge bg-primary rounded-pill"><?= count($tiposCentro) ?> clasificaciones</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle mb-0">
                                    <thead class="table-light text-muted small text-uppercase" style="font-size: 0.73rem;">
                                        <tr>
                                            <th style="width: 50px;">ID</th>
                                            <th style="width: 140px;">Código</th>
                                            <th>Clasificación Oficial</th>
                                            <th>Descripción y Alcance</th>
                                            <th style="width: 110px;" class="text-center">Sedes Registradas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tiposCentro as $t): ?>
                                            <tr>
                                                <td class="text-muted fw-bold">#<?= (int)$t['id'] ?></td>
                                                <td><span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($t['codigo']) ?></span></td>
                                                <td class="fw-semibold text-dark"><?= htmlspecialchars($t['nombre']) ?></td>
                                                <td class="small text-muted"><?= htmlspecialchars($t['descripcion'] ?? '—') ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-primary rounded-pill"><?= (int)$t['total_centros'] ?> sedes</span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer bg-light py-2 px-3 text-muted small border-top">
                            Cualquier clasificación añadida aquí se integra inmediatamente al selector <code>&lt;select&gt;</code> de registro sin tocar código PHP.
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
