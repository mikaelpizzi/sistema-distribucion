<?php
// src/views/tabs/medicamentos/subtab_vademecum.php
// SICM | Subpestaña: Vademécum de Medicamentos y Catálogo Central
declare(strict_types=1);
?>
<!-- Modal de Registro / Edición de Fármaco -->
<div class="modal fade" id="modalMedicamento" tabindex="-1" aria-labelledby="modalMedicamentoLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="procesar.php" novalidate id="form-medicamento">
                <div class="modal-header bg-light py-2 px-3 border-bottom">
                    <h6 class="modal-title fw-bold" id="modalMedicamentoLabel">
                        <i
                            class="bi <?= $medicamentoEdicion ? 'bi-pencil-square text-warning' : 'bi-capsule text-primary' ?> me-1"></i>
                        <?= $medicamentoEdicion ? 'Editar Especificaciones de Fármaco #' . (int) $medicamentoEdicion['id'] : 'Registrar Nuevo Fármaco' ?>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
                </div>
                <div class="modal-body p-3">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="accion"
                        value="<?= $medicamentoEdicion ? 'editar_medicamento' : 'crear_medicamento' ?>">
                    <?php if ($medicamentoEdicion): ?>
                        <input type="hidden" name="id" value="<?= (int) $medicamentoEdicion['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-2 mb-2">
                        <div class="col-md-7">
                            <label class="form-label-sicm mb-1">Nombre Genérico (Principio Activo) <span
                                    class="text-danger">*</span></label>
                            <input type="text" name="nombre_generico"
                                class="form-control form-control-sm" placeholder="Ej: Losartán Potásico"
                                required minlength="2" maxlength="120"
                                value="<?= htmlspecialchars($medicamentoEdicion['nombre_generico'] ?? '') ?>">
                            <div class="form-text" style="font-size: 0.70rem;">Denominación Común
                                Internacional (DCI).</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label-sicm mb-1">Concentración / Dosificación <span
                                    class="text-danger">*</span></label>
                            <input type="text" name="concentracion" class="form-control form-control-sm"
                                placeholder="Ej: 50mg, 100UI/ml, 850mg" required minlength="1"
                                maxlength="50"
                                value="<?= htmlspecialchars($medicamentoEdicion['concentracion'] ?? '') ?>">
                            <div class="form-text" style="font-size: 0.70rem;">Fuerza activa o dosis
                                unitaria.</div>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label-sicm mb-1">Forma Farmacéutica <span
                                    class="text-danger">*</span></label>
                            <select name="forma_farmaceutica_id" class="form-select form-select-sm"
                                required>
                                <option value="">-- Seleccionar Presentación --</option>
                                <?php foreach ($formasFarmaceuticas as $forma): ?>
                                    <option value="<?= $forma['id'] ?>" <?= ($medicamentoEdicion && (int) $medicamentoEdicion['forma_farmaceutica_id'] === (int) $forma['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($forma['nombre']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-sicm mb-1">Stock Mínimo Sugerido (Umbral de
                                Alerta)</label>
                            <input type="number" name="stock_minimo_sugerido"
                                class="form-control form-control-sm" min="1" max="99999" required
                                value="<?= (int) ($medicamentoEdicion['stock_minimo_sugerido'] ?? 20) ?>">
                            <div class="form-text" style="font-size: 0.70rem;">Dispara avisos
                                automáticos de reposición en ambulatorios.</div>
                        </div>
                    </div>

                    <div class="p-2.5 bg-light border rounded">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch"
                                id="switchCadenaFrio" name="requiere_cadena_frio" value="1"
                                <?= ($medicamentoEdicion && (int) $medicamentoEdicion['requiere_cadena_frio'] === 1) ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-semibold text-dark"
                                for="switchCadenaFrio">
                                <i class="bi bi-snow2 text-info me-1"></i> Requiere Cadena de Frío
                                Estricta
                            </label>
                        </div>
                        <div class="text-muted mt-1" style="font-size: 0.69rem; line-height: 1.3;">
                            Refrigeración clínica continua entre <strong>2°C y 8°C</strong>. Su
                            dispensación exige ambulatorios con equipo de frío certificado.
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2 px-3 border-top d-flex justify-content-between">
                    <?php if ($medicamentoEdicion): ?>
                        <a href="index.php?tab=medicamentos&subtab=vademecum"
                            class="btn btn-outline-secondary btn-sm">Cancelar edición</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm"
                            data-bs-dismiss="modal">Cerrar</button>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                        <i
                            class="bi <?= $medicamentoEdicion ? 'bi-check2-circle' : 'bi-plus-lg' ?> me-1"></i>
                        <?= $medicamentoEdicion ? 'Guardar Cambios' : 'Registrar Medicamento' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Tabla de Medicamentos (100% de ancho) -->
    <div class="col-12">
        <div class="card-sicm">
            <div
                class="card-header-sicm d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
                <div class="d-flex align-items-center gap-2">
                    <h6 class="m-0 fw-bold">
                        <i class="bi bi-list-check text-primary me-1"></i>
                        Vademécum Municipal (<?= count($medicamentos) ?> fármacos)
                    </h6>
                    <?php if (tienePermiso('MED_CREAR')): ?>
                        <button type="button"
                            class="btn btn-primary btn-sm px-3 d-inline-flex align-items-center gap-1 shadow-sm ms-2"
                            data-bs-toggle="modal" data-bs-target="#modalMedicamento">
                            <i class="bi bi-plus-lg me-1"></i>Registrar Fármaco
                        </button>
                    <?php endif; ?>
                </div>
                <form method="GET" action="index.php"
                    class="d-flex flex-wrap align-items-center gap-1 m-0">
                    <input type="hidden" name="tab" value="medicamentos">
                    <input type="hidden" name="subtab" value="vademecum">
                    <select name="estado_med" class="form-select form-select-sm"
                        style="width: auto; font-size: 0.78rem;" onchange="this.form.submit()">
                        <option value="todos" <?= ($filtroEstadoMed === 'todos') ? 'selected' : '' ?>>Todos
                            los Estados</option>
                        <option value="activos" <?= ($filtroEstadoMed === 'activos') ? 'selected' : '' ?>>
                            Solo Operativos</option>
                        <option value="descontinuados" <?= ($filtroEstadoMed === 'descontinuados') ? 'selected' : '' ?>>Solo Descontinuados</option>
                    </select>
                    <div class="input-group input-group-sm" style="width: 190px;">
                        <input type="text" name="q_med" class="form-control"
                            placeholder="Buscar principio..."
                            value="<?= htmlspecialchars($busquedaMed) ?>">
                        <button class="btn btn-outline-secondary" type="submit"><i
                                class="bi bi-search"></i></button>
                    </div>
                    <?php if ($busquedaMed !== '' || $filtroEstadoMed !== 'todos'): ?>
                        <a href="index.php?tab=medicamentos&subtab=vademecum"
                            class="btn btn-outline-danger btn-sm py-1 px-2" title="Limpiar Filtros">
                            <i class="bi bi-x-circle"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 font-sans"
                    style="font-size: 0.82rem;">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 45px;">#ID</th>
                            <th>Nombre Genérico</th>
                            <th>Concentración</th>
                            <th>Forma Farmacéutica</th>
                            <th>Cadena de Frío</th>
                            <th class="text-center" style="width: 85px;">Stock Mín.</th>
                            <th class="text-center" style="width: 95px;">Estado</th>
                            <th class="text-end" style="width: 190px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($medicamentos)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                                    No se encontraron medicamentos en el vademécum con los filtros
                                    indicados.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($medicamentos as $med): ?>
                                <tr
                                    class="<?= ((int) $med['activo'] === 0) ? 'table-secondary opacity-75' : '' ?>">
                                    <td class="font-monospace text-muted">#<?= $med['id'] ?></td>
                                    <td>
                                        <span
                                            class="fw-bold text-dark"><?= htmlspecialchars($med['nombre_generico']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border font-monospace"
                                            style="font-size: 0.72rem;">
                                            <?= htmlspecialchars($med['concentracion']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            class="text-secondary"><?= htmlspecialchars($med['forma_nombre']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ((int) $med['requiere_cadena_frio'] === 1): ?>
                                            <span class="badge bg-info text-dark" style="font-size: 0.72rem;">
                                                <i class="bi bi-snow2 me-1"></i>❄️ Refrigerado (2-8°C)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border"
                                                style="font-size: 0.72rem;">
                                                Ambiente
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border font-monospace">
                                            <?= number_format((int) $med['stock_minimo_sugerido']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int) $med['activo'] === 1): ?>
                                            <span
                                                class="badge bg-success-subtle text-success border border-success-subtle"
                                                style="font-size: 0.72rem;">
                                                Operativo
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                style="font-size: 0.72rem;">
                                                Descontinuado
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <?php if (tienePermiso('AUDIT_VER')): ?>
                                                <a href="index.php?tab=auditoria&subtab=deltas&tabla=medicamentos&registro_id=<?= $med['id'] ?>"
                                                    class="btn btn-outline-secondary btn-sm py-0.5 px-2"
                                                    style="font-size: 0.75rem;"
                                                    title="Ver Auditoría Delta celda por celda">
                                                    <i class="bi bi-clock-history"></i> Historial
                                                </a>
                                            <?php endif; ?>
                                            <?php if (tienePermiso('MED_EDITAR')): ?>
                                                <a href="index.php?tab=medicamentos&subtab=vademecum&editar_med=<?= $med['id'] ?>"
                                                    class="btn btn-outline-primary btn-sm py-0.5 px-2"
                                                    style="font-size: 0.75rem;"
                                                    title="Editar Especificaciones de Fármaco">
                                                    <i class="bi bi-pencil-square"></i> Editar
                                                </a>
                                                <form method="POST" action="procesar.php" class="d-inline"
                                                    onsubmit="return confirm('¿Confirma que desea <?= ((int) $med['activo'] === 1) ? 'descontinuar' : 'reactivar' ?> el medicamento <?= htmlspecialchars(addslashes($med['nombre_generico'])) ?>?');">
                                                    <input type="hidden" name="csrf_token"
                                                        value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="accion"
                                                        value="cambiar_estado_medicamento">
                                                    <input type="hidden" name="id" value="<?= $med['id'] ?>">
                                                    <button type="submit"
                                                        class="btn btn-sm py-0.5 px-2 <?= ((int) $med['activo'] === 1) ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                        style="font-size: 0.75rem;"
                                                        title="<?= ((int) $med['activo'] === 1) ? 'Descontinuar fármaco' : 'Reactivar fármaco' ?>">
                                                        <?= ((int) $med['activo'] === 1) ? 'Desactivar' : 'Reactivar' ?>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($medicamentoEdicion)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const mMed = document.getElementById('modalMedicamento');
            if (mMed) new bootstrap.Modal(mMed).show();
        });
    </script>
<?php endif; ?>
