<?php
// src/views/tabs/medicamentos/subtab_patologias.php
// SICM | Subpestaña: Catálogo de Patologías (CIE-10)
declare(strict_types=1);
?>
<div class="row g-3">
    <!-- Formulario de Registro de Patología -->
    <div class="col-xl-4 col-lg-5">
        <div class="card-sicm">
            <div class="card-header-sicm">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-plus-circle text-primary me-1"></i>
                    Registrar Patología (CIE-10)
                </h6>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="procesar.php" novalidate id="form-patologia">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="accion" value="crear_patologia">

                    <div class="mb-3">
                        <label class="form-label-sicm">Código CIE-10 (Opcional)</label>
                        <input type="text" name="codigo_cie10"
                            class="form-control form-control-sm font-monospace text-uppercase"
                            placeholder="Ej: I10, E11, J45" maxlength="10">
                        <div class="form-text" style="font-size: 0.70rem;">Clasificación Internacional
                            de Enfermedades (CIE-10).</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-sicm">Diagnóstico / Denominación <span
                                class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control form-control-sm"
                            placeholder="Ej: Hipertensión Arterial Primaria" required minlength="3"
                            maxlength="120">
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="cronica" value="1"
                                id="checkCronica" checked>
                            <label class="form-check-label small fw-semibold text-dark"
                                for="checkCronica">
                                Enfermedad Crónica No Transmisible
                            </label>
                        </div>
                        <div class="text-muted" style="font-size: 0.70rem;">
                            Indica patología elegible para récipe de suministro continuo mensual.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-sicm">Descripción o Criterios Clínicos</label>
                        <textarea name="descripcion" class="form-control form-control-sm" rows="3"
                            placeholder="Detalles sobre criterios diagnósticos o estadio evolutivo..."
                            maxlength="255"></textarea>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                        <span class="text-muted small" style="font-size: 0.70rem;"><span
                                class="text-danger">*</span> Campo requerido</span>
                        <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-plus-lg me-1"></i> Registrar Patología
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Tabla de Patologías -->
    <div class="col-xl-8 col-lg-7">
        <div class="card-sicm">
            <div class="card-header-sicm d-flex justify-content-between align-items-center py-2">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-heart-pulse text-primary me-1"></i>
                    Catálogo Clínico Parametrizable (<?= count($patologias) ?> patologías)
                </h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.82rem;">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 45px;">#ID</th>
                            <th style="width: 95px;">CIE-10</th>
                            <th>Diagnóstico / Denominación</th>
                            <th class="text-center" style="width: 110px;">Clasificación</th>
                            <th class="text-center" style="width: 90px;">Estado</th>
                            <th class="text-end" style="width: 110px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($patologias)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No hay patologías registradas en el catálogo clínico.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($patologias as $pat): ?>
                                <tr
                                    class="<?= ((int) $pat['activo'] === 0) ? 'table-secondary opacity-75' : '' ?>">
                                    <td class="font-monospace text-muted">#<?= $pat['id'] ?></td>
                                    <td>
                                        <?php if (!empty($pat['codigo_cie10'])): ?>
                                            <span class="badge bg-light text-dark border font-monospace">
                                                <?= htmlspecialchars($pat['codigo_cie10']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small"><em>(Sin CIE)</em></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($pat['nombre']) ?>
                                        </div>
                                        <?php if (!empty($pat['descripcion'])): ?>
                                            <div class="text-muted small"
                                                style="font-size: 0.72rem; line-height: 1.2;">
                                                <?= htmlspecialchars($pat['descripcion']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int) $pat['cronica'] === 1): ?>
                                            <span
                                                class="badge bg-primary-subtle text-primary border border-primary-subtle"
                                                style="font-size: 0.72rem;">
                                                Crónica
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"
                                                style="font-size: 0.72rem;">
                                                Aguda
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int) $pat['activo'] === 1): ?>
                                            <span
                                                class="badge bg-success-subtle text-success border border-success-subtle"
                                                style="font-size: 0.72rem;">
                                                Activo
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                style="font-size: 0.72rem;">
                                                Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="procesar.php" class="d-inline"
                                            onsubmit="return confirm('¿Confirma que desea <?= ((int) $pat['activo'] === 1) ? 'desactivar' : 'reactivar' ?> la patología <?= htmlspecialchars(addslashes($pat['nombre'])) ?>?');">
                                            <input type="hidden" name="csrf_token"
                                                value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="cambiar_estado_patologia">
                                            <input type="hidden" name="id" value="<?= $pat['id'] ?>">
                                            <button type="submit"
                                                class="btn btn-sm py-0.5 px-2 <?= ((int) $pat['activo'] === 1) ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                style="font-size: 0.75rem;">
                                                <?= ((int) $pat['activo'] === 1) ? 'Desactivar' : 'Reactivar' ?>
                                            </button>
                                        </form>
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
