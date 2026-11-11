<header class="sicm-topbar">
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1.5 px-2.5 py-1 me-2"
                        id="btn-toggle-sidebar" type="button" title="Ocultar o mostrar menú lateral"
                        aria-label="Alternar menú lateral">
                        <i class="bi bi-list fs-5" id="icon-toggle-sidebar"></i>
                        <span class="d-none d-md-inline small fw-semibold text-secondary">Menú</span>
                    </button>
                    <nav class="sicm-breadcrumb" aria-label="breadcrumb">
                        <span class="text-secondary"><?= htmlspecialchars($seccionNombre ?? 'Vademécum y Red') ?></span>
                        <i class="bi bi-chevron-right text-muted" style="font-size: 0.65rem;"></i>
                        <span class="sicm-breadcrumb-active"><?= htmlspecialchars($moduloNombre ?? 'Red Asistencial') ?></span>
                    </nav>
                </div>
            </header>

<!-- Alertas de Flash Session -->
<?php if (!empty($mensajeExito)): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 py-2.5 px-3 mb-3 border shadow-sm"
        role="alert">
        <i class="bi bi-check-circle-fill text-success fs-5 flex-shrink-0"></i>
        <div class="small fw-medium">
            <?= htmlspecialchars((string) $mensajeExito, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
<?php endif; ?>

<?php if (!empty($mensajeError)): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 py-2.5 px-3 mb-3 border shadow-sm"
        role="alert">
        <i class="bi bi-exclamation-octagon-fill text-danger fs-5 flex-shrink-0"></i>
        <div class="small fw-medium">
            <?= htmlspecialchars((string) $mensajeError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
<?php endif; ?>
