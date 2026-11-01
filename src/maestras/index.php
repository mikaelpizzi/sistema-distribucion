<?php
// src/maestras/index.php
// Ficha Maestra y Configurador Dinámico de Formulario (EAV + N:M)
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$mensajeExito = $_GET['msg'] ?? null;
$mensajeError = $_GET['error'] ?? null;

// 1. Obtener campos configurables (EAV)
$camposEav = $pdo->query("SELECT id, nombre, clave, tipo_dato, activo FROM campos_configurables ORDER BY id ASC")->fetchAll();
$camposActivos = array_filter($camposEav, fn($c) => (int)$c['activo'] === 1);

// 2. Obtener recursos del catálogo (N:M)
$recursosCatalogo = $pdo->query("SELECT id, codigo, nombre, descripcion FROM recursos_operativos WHERE activo = 1 ORDER BY id ASC")->fetchAll();

// 3. Obtener tipos de centro
$tiposCentro = $pdo->query("SELECT id, codigo, nombre FROM tipos_centro_salud ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SICM | Ficha Maestra de Centros</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f1f5f9;
            font-size: 0.85rem;
            color: #1e293b;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        .header-slim {
            background-color: #0b1329;
            border-bottom: 2px solid #2563eb;
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

        .card {
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid #cbd5e1;
        }

        .card-header-clean {
            background-color: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.6rem 0.9rem;
            font-weight: 700;
            font-size: 0.90rem;
        }

        .section-subtitle {
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.35rem;
        }

        .form-switch .form-check-input {
            width: 2.2em;
            height: 1.15em;
            cursor: pointer;
        }

        .form-switch .form-check-input:checked {
            background-color: #16a34a;
            border-color: #16a34a;
        }

        .badge-dynamic {
            font-size: 0.70rem;
            background-color: #e2e8f0;
            color: #475569;
            font-weight: 500;
            padding: 0.15rem 0.4rem;
            border-radius: 3px;
        }
    </style>
</head>

<body>

    <!-- Header Técnico de Navegación Unificada -->
    <header class="header-slim text-white py-2 px-3 d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold tracking-wide text-info fs-6">SICM-RED</span>
            <span class="text-white-50">|</span>
            <nav class="d-flex gap-1">
                <a href="../centros/index.php" class="nav-link-tech">Red de Centros</a>
                <a href="index.php" class="nav-link-tech active">Tablas Maestras (Activo)</a>
                <a href="../auditoria/index.php" class="nav-link-tech">Auditoría Forense</a>
            </nav>
        </div>
        <div class="text-white-50 font-monospace small" style="font-size: 0.76rem;">
            Módulo: <span class="text-white">Ficha Maestra de Centros</span> | Motor: <span class="text-white">MariaDB</span>
        </div>
    </header>

    <div class="container-fluid px-3 py-3">

        <!-- Título Principal -->
        <h5 class="fw-bold text-dark mb-2">Ficha Maestra: Registrar y Configurar Nuevo Centro de Salud</h5>

        <!-- Notificación de Principio Arquitectónico -->
        <div class="alert alert-info border-info-subtle py-2 px-3 mb-3 small d-flex align-items-center justify-content-between">
            <div>
                <strong>Adaptabilidad de Formulario (EAV y N:M):</strong> El Administrador puede añadir, quitar o desactivar los campos del formulario y capacidades del centro de salud en caliente, sin alterar la estructura física de la base de datos (cero <code>ALTER TABLE</code>).
            </div>
            <span class="badge bg-primary text-uppercase font-monospace" style="font-size: 0.65rem;">Modo Paramétrico</span>
        </div>

        <?php if ($mensajeExito): ?>
            <div class="alert alert-success alert-dismissible fade show py-2 shadow-sm mb-3" role="alert">
                <strong>Operación Exitosa:</strong> <?= htmlspecialchars($mensajeExito) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mensajeError): ?>
            <div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm mb-3" role="alert">
                <strong>Error:</strong> <?= htmlspecialchars($mensajeError) ?>
                <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-3">
            
            <!-- ================================================================ -->
            <!-- COLUMNA IZQUIERDA: CONFIGURADOR DE FORMULARIO (CAMPOS Y EAV)     -->
            <!-- ================================================================ -->
            <div class="col-xl-5 col-lg-5">
                <div class="card">
                    <div class="card-header-clean text-dark">
                        Configurador de Formulario (Campos Dinámicos y EAV)
                    </div>
                    <div class="card-body p-3">
                        
                        <!-- Bloque 1: Personalizar Campos Extra -->
                        <div class="mb-4">
                            <div class="section-subtitle">Personalizar Campos Extra</div>
                            <p class="text-muted small mb-2" style="font-size: 0.77rem;">
                                Activa, desactiva o elimina los campos del formulario según las necesidades actuales de la red.
                            </p>

                            <button type="button" class="btn btn-primary btn-sm mb-3 fw-semibold py-1 px-3" data-bs-toggle="modal" data-bs-target="#modalNuevoCampo">
                                + Añadir Nuevo Campo al Formulario
                            </button>

                            <!-- Lista de Campos Configurables -->
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($camposEav as $campo): ?>
                                    <?php 
                                        $isOn = (int)$campo['activo'] === 1;
                                        $tipoEtiqueta = match($campo['tipo_dato']) {
                                            'numero'   => '[Número]',
                                            'booleano' => '[Booleano]',
                                            default    => '[Texto]'
                                        };
                                    ?>
                                    <div class="d-flex align-items-center justify-content-between p-2 border rounded bg-white">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="form-check form-switch m-0">
                                                <input class="form-check-input switch-eav" type="checkbox" role="switch"
                                                       data-id="<?= (int)$campo['id'] ?>" <?= $isOn ? 'checked' : '' ?>>
                                            </div>
                                            <span class="fw-semibold small text-dark">
                                                <?= htmlspecialchars($campo['nombre']) ?> 
                                                <span class="text-muted fw-normal" style="font-size: 0.74rem;"><?= $tipoEtiqueta ?></span>
                                            </span>
                                        </div>
                                        <a href="procesar.php?accion=eliminar_campo_eav&id=<?= (int)$campo['id'] ?>" 
                                           class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 0.72rem;"
                                           onclick="return confirm('¿Eliminar definitivamente este campo configurable del formulario?')">
                                            Eliminar
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Bloque 2: Capacidades del Catálogo (N:M) -->
                        <div class="pt-3 border-top">
                            <div class="section-subtitle">Capacidades del Catálogo (N:M)</div>
                            <p class="text-muted small mb-2" style="font-size: 0.77rem;">
                                Recursos base de la red se heredarán en el formulario:
                            </p>

                            <div class="d-flex flex-column gap-1 bg-light p-2 border rounded">
                                <?php foreach ($recursosCatalogo as $rc): ?>
                                    <div class="form-check form-check-sm m-0">
                                        <input class="form-check-input" type="checkbox" id="cat_rec_<?= (int)$rc['id'] ?>" checked disabled>
                                        <label class="form-check-label small text-dark" for="cat_rec_<?= (int)$rc['id'] ?>">
                                            <?= htmlspecialchars($rc['nombre']) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ================================================================ -->
            <!-- COLUMNA DERECHA: FICHA DEL AMBULATORIO (REGISTRO DINÁMICO)       -->
            <!-- ================================================================ -->
            <div class="col-xl-7 col-lg-7">
                <div class="card">
                    <div class="card-header-clean text-dark">
                        Ficha del Ambulatorio: Registro
                    </div>
                    <div class="card-body p-3">
                        <form action="procesar.php" method="POST">
                            <input type="hidden" name="accion" value="guardar_ficha_centro">

                            <div class="mb-3">
                                <div class="fw-bold text-dark small mb-0">Formulario de Registro: Nuevo Centro de Salud</div>
                                <div class="text-muted small" style="font-size: 0.77rem;">
                                    Este formulario se adapta a las necesidades de la empresa:
                                </div>
                            </div>

                            <!-- 1. Datos Básicos de la Sede -->
                            <div class="mb-3">
                                <div class="section-subtitle">Datos Básicos de la Sede</div>
                                
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label for="nombre_centro" class="form-label small fw-semibold mb-1">Nombre del Centro de Salud</label>
                                        <input type="text" class="form-control form-control-sm" id="nombre_centro" name="nombre" required 
                                               placeholder="Ej: Ambulatorio Urbano Los Curos">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="codigo_sicm" class="form-label small fw-semibold mb-1">Código Sanitario (SICM)</label>
                                        <input type="text" class="form-control form-control-sm font-monospace text-uppercase" id="codigo_sicm" name="codigo_sicm" required 
                                               placeholder="Ej: AMB-MER-004">
                                    </div>
                                </div>

                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label for="direccion_sede" class="form-label small fw-semibold mb-1">Dirección Física / Sector</label>
                                        <textarea class="form-control form-control-sm" id="direccion_sede" name="direccion" rows="2" required 
                                                  placeholder="Sector, calle o avenida principal"></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="tipo_centro" class="form-label small fw-semibold mb-1">Clasificación de Sede (1:N)</label>
                                        <select class="form-select form-select-sm" id="tipo_centro" name="tipo_centro_id" required>
                                            <option value="">-- Seleccionar Clasificación --</option>
                                            <?php foreach ($tiposCentro as $t): ?>
                                                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- 2. Capacidades y Recursos de la Sede (N:M) -->
                            <div class="mb-3 pt-2 border-top">
                                <div class="section-subtitle">Capacidades y Recursos de la Sede (N:M) <span class="text-muted fw-normal" style="font-size: 0.74rem;">(heredados del catálogo)</span></div>

                                <div class="row g-2">
                                    <?php foreach ($recursosCatalogo as $rec): ?>
                                        <div class="col-md-6">
                                            <div class="form-check form-check-sm">
                                                <input class="form-check-input" type="checkbox" name="recursos[]" 
                                                       value="<?= (int)$rec['id'] ?>" id="form_rec_<?= (int)$rec['id'] ?>">
                                                <label class="form-check-label small" for="form_rec_<?= (int)$rec['id'] ?>">
                                                    <?= htmlspecialchars($rec['nombre']) ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- 3. Campos Adicionales Configurados (EAV) -->
                            <div class="mb-4 pt-2 border-top">
                                <div class="section-subtitle">Campos Adicionales Configurados (EAV)</div>

                                <?php if (empty($camposActivos)): ?>
                                    <div class="text-muted small fst-italic py-2">
                                        No hay campos dinámicos activados en este momento. Activa o crea campos en la columna izquierda.
                                    </div>
                                <?php else: ?>
                                    <div class="row g-2">
                                        <?php foreach ($camposActivos as $ca): ?>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-semibold mb-1" for="eav_<?= (int)$ca['id'] ?>">
                                                    <?= htmlspecialchars($ca['nombre']) ?> 
                                                    <span class="badge-dynamic">Dinámico</span>
                                                </label>
                                                
                                                <?php if ($ca['tipo_dato'] === 'numero'): ?>
                                                    <input type="number" class="form-control form-control-sm" 
                                                           id="eav_<?= (int)$ca['id'] ?>" name="eav[<?= (int)$ca['id'] ?>]" placeholder="0">
                                                <?php elseif ($ca['tipo_dato'] === 'booleano'): ?>
                                                    <select class="form-select form-select-sm" id="eav_<?= (int)$ca['id'] ?>" name="eav[<?= (int)$ca['id'] ?>]">
                                                        <option value="1">Sí / Disponible</option>
                                                        <option value="0" selected>No / No disponible</option>
                                                    </select>
                                                <?php else: ?>
                                                    <input type="text" class="form-control form-control-sm" 
                                                           id="eav_<?= (int)$ca['id'] ?>" name="eav[<?= (int)$ca['id'] ?>]" placeholder="Valor...">
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Botones de Acción -->
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold">
                                    Guardar Ficha de Centro
                                </button>
                                <a href="../centros/index.php" class="btn btn-secondary btn-sm px-3">
                                    Cancelar
                                </a>
                            </div>

                        </form>
                    </div>
                </div>
            </div>

        </div>

    </div>

    <!-- MODAL: AÑADIR NUEVO CAMPO DINÁMICO (EAV) -->
    <div class="modal fade" id="modalNuevoCampo" tabindex="-1" aria-labelledby="modalNuevoCampoLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header bg-dark text-white py-2 px-3">
                    <h6 class="modal-title font-monospace" id="modalNuevoCampoLabel">Añadir Campo al Formulario Dinámico</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <form action="procesar.php" method="POST">
                    <input type="hidden" name="accion" value="crear_campo_eav">
                    <div class="modal-body p-3">
                        <div class="mb-3">
                            <label for="nombre_nuevo_campo" class="form-label small fw-semibold mb-1">Nombre del Campo</label>
                            <input type="text" class="form-control form-control-sm" id="nombre_nuevo_campo" name="nombre" 
                                   placeholder="Ej: Tomógrafo Computarizado, Capacidad de Oxígeno..." required maxlength="60">
                            <div class="form-text" style="font-size: 0.72rem;">Aparecerá en el formulario como un nuevo atributo de la ficha.</div>
                        </div>

                        <div class="mb-2">
                            <label for="tipo_dato_nuevo" class="form-label small fw-semibold mb-1">Tipo de Dato</label>
                            <select class="form-select form-select-sm" id="tipo_dato_nuevo" name="tipo_dato" required>
                                <option value="texto">Texto (VARCHAR / Cadena descriptiva)</option>
                                <option value="numero">Número (INT / Conteo, metros, capacidad)</option>
                                <option value="booleano">Booleano (Sí / No, Checkbox)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer py-2 px-3 bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm fw-semibold">Incorporar al Formulario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Manejador reactivo para los toggles ON/OFF
            document.querySelectorAll('.switch-eav').forEach(sw => {
                sw.addEventListener('change', (e) => {
                    const campoId = sw.getAttribute('data-id');
                    const nuevoEstado = sw.checked ? 1 : 0;

                    const formData = new FormData();
                    formData.append('accion', 'toggle_campo_eav');
                    formData.append('id', campoId);
                    formData.append('estado', nuevoEstado);

                    fetch('procesar.php', {
                        method: 'POST',
                        body: formData
                    }).then(() => {
                        window.location.reload();
                    }).catch(err => {
                        console.error('Error al actualizar campo dinámico:', err);
                    });
                });
            });
        });
    </script>
</body>

</html>
