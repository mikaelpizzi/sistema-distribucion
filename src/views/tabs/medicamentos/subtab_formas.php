<?php
// src/views/tabs/medicamentos/subtab_formas.php
// SICM | Subpestaña: Catálogo de Formas Farmacéuticas
declare(strict_types=1);
?>
<div class="row g-3">
    <!-- Formulario de Registro de Forma Farmacéutica -->
    <div class="col-xl-4 col-lg-5">
        <div class="card-sicm">
            <div class="card-header-sicm">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-plus-circle text-primary me-1"></i>
                    Nueva Forma Farmacéutica
                </h6>
            </div>
            <div class="card-body p-3">
                <form method="POST" action="procesar.php" novalidate id="form-forma">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="accion" value="crear_forma_farmaceutica">

                    <div class="mb-3">
                        <label class="form-label-sicm">Denominación de Presentación <span
                                class="text-danger">*</span></label>
                        <input type="text" name="nombre" class="form-control form-control-sm"
                            placeholder="Ej: Tableta, Cápsula, Jarabe, Ampolla..." required
                            minlength="2" maxlength="60">
                        <div class="form-text" style="font-size: 0.70rem;">
                            Vía o formato físico de administración del principio activo.
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                        <span class="text-muted small" style="font-size: 0.70rem;"><span
                                class="text-danger">*</span> Campo requerido</span>
                        <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-plus-lg me-1"></i> Registrar Forma
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Tabla de Formas Farmacéuticas -->
    <div class="col-xl-8 col-lg-7">
        <div class="card-sicm">
            <div class="card-header-sicm d-flex justify-content-between align-items-center py-2">
                <h6 class="m-0 fw-bold">
                    <i class="bi bi-boxes text-primary me-1"></i>
                    Formas de Presentación Farmacéutica (<?= count($formasFarmaceuticas) ?>)
                </h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.82rem;">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">#ID</th>
                            <th>Forma Farmacéutica / Presentación</th>
                            <th class="text-center" style="width: 140px;">Fármacos Vinculados</th>
                            <th class="text-center" style="width: 120px;">Condición</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($formasFarmaceuticas)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    No se han registrado formas farmacéuticas.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($formasFarmaceuticas as $f): ?>
                                <tr>
                                    <td class="font-monospace text-muted">#<?= $f['id'] ?></td>
                                    <td class="fw-bold text-dark">
                                        <i class="bi bi-capsule text-primary me-1"></i>
                                        <?= htmlspecialchars($f['nombre']) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border font-monospace">
                                            <?= number_format((int) $f['total_medicamentos']) ?> fármaco(s)
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int) $f['total_medicamentos'] > 0): ?>
                                            <span
                                                class="badge bg-success-subtle text-success border border-success-subtle"
                                                style="font-size: 0.72rem;">
                                                En Uso Activo
                                            </span>
                                        <?php else: ?>
                                            <span
                                                class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                                style="font-size: 0.72rem;">
                                                Disponible
                                            </span>
                                        <?php endif; ?>
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
