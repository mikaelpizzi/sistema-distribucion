<?php
// src/views/tabs/auditoria/subtab_eventos.php
// SICM | Subpestaña: Bitácora Forense WORM y Visor JSON
declare(strict_types=1);
?>
<div class="card-sicm shadow-sm" id="forensic-table-container" data-subtab="eventos"
    data-page="<?= $paginaActual ?>" data-max-id="<?= $maxLogId ?>"
    data-has-filters="<?= $hayFiltrosActivos ? '1' : '0' ?>">
    <div class="card-header-sicm d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-journal-text text-primary"></i>
            <span class="fw-bold">Bitácora de Eventos</span>
            <span class="text-muted small fw-normal">(Mostrando <?= count($logs) ?> de
                <?= number_format($totalFiltrados) ?> eventos)</span>
        </div>
        <!-- Filtro Específico de Eventos: Acción -->
        <div class="d-flex align-items-center gap-2">
            <form method="GET" action="index.php" class="d-flex align-items-center gap-1 m-0">
                <input type="hidden" name="tab" value="auditoria">
                <input type="hidden" name="subtab" value="eventos">
                <?php if ($filtroTabla !== 'todas' && $filtroTabla !== ''): ?>
                    <input type="hidden" name="tabla_filtro" value="<?= htmlspecialchars($filtroTabla) ?>">
                <?php endif; ?>
                <?php if ($filtroUsuario !== 'todos' && $filtroUsuario !== ''): ?>
                    <input type="hidden" name="usuario_filtro" value="<?= htmlspecialchars($filtroUsuario) ?>">
                <?php endif; ?>
                <?php if ($filtroTipo !== 'todos' && $filtroTipo !== ''): ?>
                    <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($filtroTipo) ?>">
                <?php endif; ?>
                <?php if ($busquedaLog !== ''): ?>
                    <input type="hidden" name="q_log" value="<?= htmlspecialchars($busquedaLog) ?>">
                <?php endif; ?>
                <label for="subtab-accion-filtro" class="text-muted small fw-semibold me-1 d-none d-sm-inline" style="font-size: 0.75rem;">Acción:</label>
                <select name="accion_filtro" id="subtab-accion-filtro" class="form-select form-select-sm" style="font-size: 0.75rem; width: auto;" onchange="this.form.submit()">
                    <option value="todas">-- Todas las acciones --</option>
                    <?php foreach ($accionesUnicas as $acc): ?>
                        <option value="<?= htmlspecialchars($acc) ?>" <?= $filtroAccion === $acc ? 'selected' : '' ?>>
                            <?= htmlspecialchars($acc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filtroAccion !== 'todas' && $filtroAccion !== ''): ?>
                    <a href="index.php?tab=auditoria&subtab=eventos<?= $filtroTabla !== 'todas' ? '&tabla_filtro=' . urlencode($filtroTabla) : '' ?>" class="btn btn-outline-danger btn-sm py-0.5 px-1.5" title="Quitar filtro de acción">
                        <i class="bi bi-x-circle"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm table-sicm mb-0">
            <thead>
                <tr>
                    <th style="width: 60px;">#ID</th>
                    <th style="width: 140px;">Fecha y Hora</th>
                    <th>Operador / Responsable</th>
                    <th style="width: 150px;">Acción</th>
                    <th>Entidad Afectada</th>
                    <th style="width: 110px;">IP Origen</th>
                    <th>Canal / Navegador</th>
                    <th style="width: 80px;" class="text-end pe-3">Detalle</th>
                </tr>
            </thead>
            <tbody id="forensic-tbody">
                <?php if (empty($logs)): ?>
                    <tr class="fila-sin-datos">
                        <td colspan="8" class="text-center py-5">
                            <div class="py-3">
                                <div class="mb-2 text-muted">
                                    <i class="bi bi-search text-secondary" style="font-size: 2.2rem;"></i>
                                </div>
                                <h6 class="text-secondary fw-semibold mb-1">No se encontraron eventos que
                                    coincidan
                                    con los criterios de búsqueda seleccionados.</h6>
                                <p class="text-muted small mb-3">Intenta cambiar los términos de búsqueda,
                                    rango de
                                    fechas o restablece los filtros para ver toda la actividad.</p>
                                <a href="index.php?tab=auditoria&subtab=eventos"
                                    class="btn btn-outline-primary btn-sm px-3 shadow-xs">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restablecer todos los
                                    filtros
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php
                    $prevLogId = null;
                    foreach ($logs as $l):
                        $currLogId = (int) $l['log_id'];

                        // Detección de Discontinuidades en secuencia
                        if ($prevLogId !== null && ($prevLogId - $currLogId) > 1):
                            $salto = ($prevLogId - 1) - $currLogId;
                            ?>
                            <tr class="table-warning border-start border-danger border-4">
                                <td colspan="8" class="text-danger fw-bold small py-2 px-3">
                                    <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>
                                    <strong>[ ! ] Discontinuidad detectada:</strong> Salto de secuencia entre ID
                                    #<?= $currLogId ?> e ID #<?= $prevLogId ?> (<?= $salto ?> registro(s)
                                    omitido(s))
                                </td>
                            </tr>
                            <?php
                        endif;
                        $prevLogId = $currLogId;

                        // Semántica cromática de severidad
                        $accion = (string) $l['accion'];
                        $ip = (string) $l['ip_origen'];
                        $severidad = 'INFO';
                        $badgeColor = 'bg-info text-dark';
                        $rowBorderClass = 'border-start border-3 border-info';

                        if (
                            in_array($accion, ['INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO'], true)
                            || $ip === 'CONSOLA_DIRECTA'
                            || str_contains($accion, 'FALLID')
                            || str_contains($accion, 'TRIGGER')
                            || str_contains($accion, 'VIOLACION')
                        ) {
                            $severidad = 'CRITICAL';
                            $badgeColor = 'bg-danger text-white';
                            $rowBorderClass = 'border-start border-4 border-danger table-danger-subtle';
                        } elseif (
                            str_contains($accion, 'DESACTIVAR')
                            || str_contains($accion, 'LOTE_AGOTADO')
                            || str_contains($accion, 'UMBRAL_MINIMO')
                            || str_contains($accion, 'REACTIVAR')
                        ) {
                            $severidad = 'WARNING';
                            $badgeColor = 'bg-warning text-dark';
                            $rowBorderClass = 'border-start border-3 border-warning';
                        } elseif (
                            str_contains($accion, 'CREAR')
                            || str_contains($accion, 'REGISTRAR')
                            || str_contains($accion, 'INICIO_SESION')
                            || str_contains($accion, 'DESPACHO_FEFO')
                            || str_contains($accion, 'INICIALIZAR')
                        ) {
                            $severidad = 'INFO';
                            $badgeColor = 'bg-success text-white';
                            $rowBorderClass = 'border-start border-3 border-success';
                        }

                        $detPreview = [];
                        try {
                            $detPreview = json_decode($l['detalles_json'] ?? '{}', true) ?: [];
                        } catch (Throwable $e) {
                        }

                        $regId = (int) ($l['registro_id'] ?? 0);
                        $esSesion = in_array($accion, ['INICIO_SESION', 'CIERRE_SESION', 'INTENTO_LOGIN_FALLIDO', 'INTENTO_LOGIN_RECHAZADO'], true);
                        $entidadInfo = resolverEntidadLegible($l['tabla_afectada'] ?? '', $regId ?: null, $detPreview, $diccionariosEntidades);
                        ?>
                        <tr class="<?= $rowBorderClass ?>">
                            <td class="code-tag">#<?= $currLogId ?></td>
                            <td class="small font-monospace"><?= htmlspecialchars($l['fecha_hora']) ?></td>
                            <td>
                                <?php if ($l['usuario_id'] === null): ?>
                                    <?php if ($ip === 'CONSOLA_DIRECTA'): ?>
                                        <div><span class="badge bg-dark"><i class="bi bi-terminal-fill me-1"></i>
                                                Consola
                                                DB</span></div>
                                        <span class="text-danger small fw-semibold" style="font-size: 0.70rem;">Acceso
                                            directo</span>
                                    <?php else: ?>
                                        <div><span class="badge bg-secondary">No autenticado</span></div>
                                        <span class="text-muted small" style="font-size: 0.70rem;">Intento
                                            fallido</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="fw-semibold text-dark">
                                        <?= htmlspecialchars($l['operador']) ?>
                                        <?php if (!empty($l['email_operador'])): ?>
                                            <span
                                                class="text-muted fw-normal small">(<?= htmlspecialchars($l['email_operador']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-secondary small" style="font-size: 0.72rem;">
                                        <i class="bi bi-person-badge text-muted me-0.5"></i>
                                        <?= htmlspecialchars($l['rol_operador'] ?? 'Usuario') ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $badgeColor ?>" style="font-size: 0.72rem;">
                                    <?= htmlspecialchars($l['accion']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($esSesion): ?>
                                    <div class="d-flex flex-column">
                                        <span class="fw-semibold text-dark" style="font-size: 0.80rem;">
                                            <i class="bi bi-shield-lock text-muted me-1"></i>Sesión de Usuario
                                        </span>
                                        <span class="text-muted small" style="font-size: 0.70rem;">
                                            <?= htmlspecialchars($detPreview['evento'] ?? 'Control de acceso') ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold text-dark text-truncate"
                                            style="max-width: 260px; font-size: 0.80rem;"
                                            title="<?= htmlspecialchars($entidadInfo['titulo']) ?>">
                                            <?= htmlspecialchars($entidadInfo['titulo']) ?>
                                        </span>
                                        <div class="mt-0.5 d-flex align-items-center gap-1">
                                            <span class="text-muted small" style="font-size: 0.70rem;">
                                                <?= htmlspecialchars($entidadInfo['subtitulo']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ip === 'CONSOLA_DIRECTA'): ?>
                                    <span class="badge bg-danger text-white font-monospace"
                                        title="Acceso directo sin servidor web">
                                        <i class="bi bi-terminal-fill me-1"></i> CLI
                                    </span>
                                <?php else: ?>
                                    <span class="code-tag"><?= htmlspecialchars($ip) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $uaRaw = $l['user_agent'] ?? 'CLI / Local';
                                $uaResumen = resumirUserAgent($uaRaw);
                                ?>
                                <div class="d-flex flex-column" title="<?= htmlspecialchars($uaRaw) ?>">
                                    <span class="fw-semibold text-dark" style="font-size: 0.76rem;">
                                        <?= htmlspecialchars($uaResumen) ?>
                                    </span>
                                    <span class="text-muted text-truncate font-monospace"
                                        style="max-width: 190px; font-size: 0.68rem;"
                                        title="<?= htmlspecialchars($uaRaw) ?>">
                                        <?= htmlspecialchars($uaRaw) ?>
                                    </span>
                                </div>
                            </td>
                            <td class="text-end pe-3">
                                <button type="button"
                                    class="btn btn-outline-primary btn-sm py-0 px-2 btn-ver-json"
                                    data-id="<?= $currLogId ?>"
                                    data-accion="<?= htmlspecialchars($l['accion']) ?>"
                                    data-tabla="<?= htmlspecialchars($l['tabla_afectada']) ?>"
                                    data-registro="<?= htmlspecialchars((string) ($l['registro_id'] ?? '')) ?>"
                                    data-operador="<?= htmlspecialchars($l['operador']) ?>"
                                    data-rol="<?= htmlspecialchars($l['rol_operador']) ?>"
                                    data-email="<?= htmlspecialchars($l['email_operador'] ?? '') ?>"
                                    data-usuario-id="<?= htmlspecialchars((string) ($l['usuario_id'] ?? '')) ?>"
                                    data-fecha="<?= htmlspecialchars($l['fecha_hora']) ?>"
                                    data-ip="<?= htmlspecialchars($l['ip_origen']) ?>"
                                    data-ua="<?= htmlspecialchars($l['user_agent'] ?? 'CLI / Local') ?>"
                                    data-ua-resumen="<?= htmlspecialchars($uaResumen) ?>"
                                    data-entidad-titulo="<?= htmlspecialchars($esSesion ? 'Sesión de Usuario' : $entidadInfo['titulo']) ?>"
                                    data-entidad-subtitulo="<?= htmlspecialchars($esSesion ? 'Control de acceso' : $entidadInfo['subtitulo']) ?>"
                                    data-json="<?= htmlspecialchars($l['detalles_json'] ?? '{}', ENT_QUOTES, 'UTF-8') ?>"
                                    style="font-size: 0.72rem;">Ver</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPaginas > 1): ?>
        <div
            class="card-footer bg-light d-flex flex-wrap justify-content-between align-items-center py-2 px-3 border-top">
            <span class="small text-muted" style="font-size: 0.78rem;">
                Mostrando <strong><?= count($logs) ?></strong> de
                <strong><?= number_format($totalFiltrados) ?></strong> eventos (Página <?= $paginaActual ?>
                de
                <?= $totalPaginas ?>)
            </span>
            <div class="btn-group btn-group-sm">
                <?php
                $queryParams = "&subtab=eventos&tabla_filtro=" . urlencode($filtroTabla)
                    . "&accion_filtro=" . urlencode($filtroAccion)
                    . "&usuario_filtro=" . urlencode($filtroUsuario)
                    . "&tipo_evento=" . urlencode($filtroTipo)
                    . "&registro_id=" . urlencode($filtroRegistroId)
                    . "&fecha_desde=" . urlencode($filtroFechaDesde)
                    . "&fecha_hasta=" . urlencode($filtroFechaHasta)
                    . "&q_log=" . urlencode($busquedaLog);
                ?>
                <?php if ($paginaActual > 1): ?>
                    <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual - 1 ?>"
                        class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">« Anterior</a>
                <?php else: ?>
                    <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;" disabled>«
                        Anterior</button>
                <?php endif; ?>

                <button class="btn btn-primary py-0 px-2 disabled"
                    style="font-size: 0.75rem;"><?= $paginaActual ?>
                    / <?= $totalPaginas ?></button>

                <?php if ($paginaActual < $totalPaginas): ?>
                    <a href="index.php?tab=auditoria<?= $queryParams ?>&p=<?= $paginaActual + 1 ?>"
                        class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">Siguiente »</a>
                <?php else: ?>
                    <button class="btn btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;"
                        disabled>Siguiente
                        »</button>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal de Detalle Técnico Forense JSON -->
<div class="modal fade" id="modalJson" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white py-2.5 px-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-shield-check text-info fs-5"></i>
                    <h6 class="modal-title font-monospace m-0">
                        Detalle del Evento #<span id="mj-id" class="text-info"></span>
                    </h6>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="mj-badge-accion" class="badge bg-primary"></span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Cerrar"></button>
                </div>
            </div>
            <div class="modal-body p-3">
                <!-- Cuadrícula Técnica de Información -->
                <div class="card bg-light border mb-3">
                    <div class="card-body p-3">
                        <div class="row g-2 small">
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Fecha y Hora:</span>
                                <div id="mj-fecha" class="font-monospace fw-bold text-dark"></div>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Operador Responsable:</span>
                                <div id="mj-actor-block" class="fw-bold text-dark"></div>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Origen de Red:</span>
                                <div>
                                    <code id="mj-ip" class="font-monospace fw-bold"></code>
                                    <span class="text-muted ms-1" id="mj-ua-summary"></span>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <span class="text-muted fw-semibold">Elemento Afectado:</span>
                                <div>
                                    <span id="mj-canal" class="badge bg-secondary font-monospace"></span>
                                    <span class="text-muted ms-1"><span id="mj-entidad-txt"
                                            class="fw-semibold text-dark"></span><code id="mj-tabla"></code> <span
                                            id="mj-registro" class="text-muted"></span></span>
                                </div>
                            </div>
                            <div class="col-12 mt-2 pt-2 border-top">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted fw-semibold" style="font-size: 0.72rem;">Navegador /
                                        Cliente Completo:</span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                        id="btn-copy-ua" style="font-size: 0.68rem;" title="Copiar User-Agent">
                                        Copiar
                                    </button>
                                </div>
                                <div id="mj-ua"
                                    class="p-2 bg-white border rounded font-monospace small text-break user-select-all"
                                    style="font-size: 0.74rem; max-height: 70px; overflow-y: auto; color: #1e293b; line-height: 1.4;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Descripción del Evento -->
                <div id="mj-evento-desc" class="alert alert-info py-2 px-3 small mb-3 d-none"></div>

                <!-- Tabla de Diferencias (Visual Diff) -->
                <div id="mj-diff-wrap" class="d-none mb-3">
                    <div class="fw-semibold small text-dark mb-1 d-flex align-items-center gap-1">
                        <i class="bi bi-arrow-left-right text-primary"></i>
                        <span>Cambios Registrados:</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 25%;">Campo</th>
                                    <th style="width: 37.5%;" class="text-danger bg-danger-subtle">Valor Anterior
                                    </th>
                                    <th style="width: 37.5%;" class="text-success bg-success-subtle">Valor Posterior
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="mj-diff-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Carga Transaccional Desglosada en Pares Clave-Valor -->
                <div id="mj-datos-wrap" class="d-none mb-3">
                    <div class="fw-semibold small text-dark mb-1 d-flex align-items-center gap-1">
                        <i class="bi bi-card-checklist text-primary"></i>
                        <span>Detalle de los Datos:</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm mb-0 small">
                            <tbody id="mj-datos-tbody"></tbody>
                        </table>
                    </div>
                </div>

                <!-- Acordeón para Inspeccionar Payload JSON Crudo -->
                <details class="mt-2">
                    <summary class="text-muted small cursor-pointer user-select-none" style="cursor: pointer;">
                        <i class="bi bi-chevron-right me-1"></i> Ver Datos Técnicos Adicionales (JSON)
                    </summary>
                    <div class="position-relative mt-2">
                        <button type="button"
                            class="btn btn-outline-light btn-sm position-absolute top-0 end-0 m-2 py-0 px-2"
                            id="btn-copy-json" style="font-size: 0.68rem; z-index: 5;">
                            Copiar JSON
                        </button>
                        <pre id="mj-contenido"
                            class="json-viewer p-2.5 bg-dark text-light rounded small font-monospace m-0"
                            style="max-height: 220px; overflow-y: auto;"></pre>
                    </div>
                </details>
            </div>
            <div class="modal-footer py-2 px-3 bg-light d-flex justify-content-between align-items-center">
                <span class="text-muted small" style="font-size: 0.75rem;">
                    <i class="bi bi-shield-check text-success me-1"></i> Registro inmutable de auditoría
                </span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Modal de Detalle Técnico del Evento
        const modalJ = document.getElementById('modalJson');
        if (modalJ) {
            const bsJ = new bootstrap.Modal(modalJ);

            window.vincularBotonVerJson = function (btn) {
                btn.addEventListener('click', () => {
                    const id = btn.getAttribute('data-id') || '';
                    const accion = btn.getAttribute('data-accion') || '';
                    const tabla = btn.getAttribute('data-tabla') || '';
                    const registro = btn.getAttribute('data-registro') || '';
                    const operador = btn.getAttribute('data-operador') || '';
                    const rol = btn.getAttribute('data-rol') || '';
                    const email = btn.getAttribute('data-email') || '';
                    const usuarioId = btn.getAttribute('data-usuario-id') || '';
                    const fecha = btn.getAttribute('data-fecha') || '';
                    const ip = btn.getAttribute('data-ip') || '';
                    const ua = btn.getAttribute('data-ua') || '';
                    const uaResumen = btn.getAttribute('data-ua-resumen') || '';
                    const entidadTitulo = btn.getAttribute('data-entidad-titulo') || '';
                    const entidadSub = btn.getAttribute('data-entidad-subtitulo') || '';
                    const raw = btn.getAttribute('data-json') || '{}';

                    document.getElementById('mj-id').textContent = id;
                    document.getElementById('mj-fecha').textContent = fecha ? `${fecha} (UTC)` : '';
                    document.getElementById('mj-tabla').textContent = tabla;
                    document.getElementById('mj-registro').textContent = registro ? `(ID #${registro})` : '';
                    document.getElementById('mj-ip').textContent = ip;
                    document.getElementById('mj-ua-summary').textContent = uaResumen ? `• ${uaResumen}` : '';

                    const entTxt = document.getElementById('mj-entidad-txt');
                    if (entTxt) {
                        entTxt.textContent = entidadTitulo ? `${entidadTitulo} • ` : '';
                    }

                    // Renderizado de Responsable
                    const actorBlock = document.getElementById('mj-actor-block');
                    if (!usuarioId) {
                        if (ip === 'CONSOLA_DIRECTA') {
                            actorBlock.innerHTML = `<span class="badge bg-dark"><i class="bi bi-terminal-fill me-1"></i> Consola DB</span> <span class="text-danger small fw-semibold">Acceso directo</span>`;
                        } else {
                            actorBlock.innerHTML = `<span class="badge bg-secondary">No autenticado</span> <span class="text-muted small">Intento fallido</span>`;
                        }
                    } else {
                        actorBlock.innerHTML = `<span class="fw-bold">${operador}</span> <span class="text-muted fw-normal">${email ? '(' + email + ')' : ''} &bull; ${rol}</span>`;
                    }

                    // Canal / Conexión
                    const canalEl = document.getElementById('mj-canal');
                    if (ip === 'CONSOLA_DIRECTA') {
                        canalEl.className = 'badge bg-danger text-white font-monospace';
                        canalEl.innerHTML = '<i class="bi bi-terminal-fill me-1"></i> CLI / Consola';
                    } else {
                        canalEl.className = 'badge bg-dark text-white font-monospace';
                        canalEl.innerHTML = '<i class="bi bi-globe me-1"></i> Web / HTTP';
                    }

                    // User-Agent estructurado
                    const uaElem = document.getElementById('mj-ua');
                    uaElem.textContent = ua || 'CLI / Local';
                    const copyUaBtn = document.getElementById('btn-copy-ua');
                    if (copyUaBtn) {
                        copyUaBtn.onclick = () => {
                            navigator.clipboard.writeText(uaElem.textContent);
                            const orig = copyUaBtn.textContent;
                            copyUaBtn.textContent = '¡Copiado!';
                            setTimeout(() => { copyUaBtn.textContent = orig; }, 1500);
                        };
                    }

                    // Badge de Acción
                    const badge = document.getElementById('mj-badge-accion');
                    badge.textContent = accion;
                    if (
                        ['INTENTO_LOGIN_FALLIDO', 'INTENTO_DOBLE_RETIRO', 'VIOLACION_REGLA_TRIGGER', 'ERROR_GENERAL_BD', 'ACCESO_DENEGADO'].includes(accion)
                        || ip === 'CONSOLA_DIRECTA' || accion.includes('FALLID') || accion.includes('TRIGGER')
                    ) {
                        badge.className = 'badge bg-danger text-white';
                    } else if (accion.includes('DESACTIVAR') || accion.includes('LOTE_AGOTADO') || accion.includes('UMBRAL_MINIMO') || accion.includes('REACTIVAR')) {
                        badge.className = 'badge bg-warning text-dark';
                    } else {
                        badge.className = 'badge bg-success text-white';
                    }

                    let parsed = {};
                    try { parsed = JSON.parse(raw); } catch (e) { parsed = { raw: raw }; }

                    // Visor JSON Crudo
                    const rawPre = document.getElementById('mj-contenido');
                    rawPre.textContent = JSON.stringify(parsed, null, 2);
                    const copyJsonBtn = document.getElementById('btn-copy-json');
                    if (copyJsonBtn) {
                        copyJsonBtn.onclick = () => {
                            navigator.clipboard.writeText(rawPre.textContent);
                            const orig = copyJsonBtn.textContent;
                            copyJsonBtn.textContent = '¡Copiado!';
                            setTimeout(() => { copyJsonBtn.textContent = orig; }, 1500);
                        };
                    }

                    // Descripción del Evento
                    const descEl = document.getElementById('mj-evento-desc');
                    if (parsed.evento) {
                        descEl.textContent = parsed.evento;
                        descEl.classList.remove('d-none');
                    } else {
                        descEl.classList.add('d-none');
                    }

                    // Diff Table o Pares Clave-Valor
                    const diffWrap = document.getElementById('mj-diff-wrap');
                    const diffTbody = document.getElementById('mj-diff-tbody');
                    const datosWrap = document.getElementById('mj-datos-wrap');
                    const datosTbody = document.getElementById('mj-datos-tbody');
                    diffTbody.innerHTML = '';
                    datosTbody.innerHTML = '';

                    if (parsed.delta && Object.keys(parsed.delta).length > 0) {
                        diffWrap.classList.remove('d-none');
                        datosWrap.classList.add('d-none');
                        for (const [campo, val] of Object.entries(parsed.delta)) {
                            const tr = document.createElement('tr');
                            const ant = typeof val.antes === 'object' ? JSON.stringify(val.antes) : (val.antes ?? '(Vacío)');
                            const des = typeof val.despues === 'object' ? JSON.stringify(val.despues) : (val.despues ?? '(Vacío)');
                            tr.innerHTML = `
                                <td class="fw-semibold text-secondary"><code>${campo}</code></td>
                                <td class="bg-danger-subtle font-monospace text-danger"><del>${ant}</del></td>
                                <td class="bg-success-subtle font-monospace text-success"><ins class="text-decoration-none fw-bold">${des}</ins></td>
                            `;
                            diffTbody.appendChild(tr);
                        }
                    } else {
                        diffWrap.classList.add('d-none');
                        const keys = Object.keys(parsed).filter(k => k !== 'evento' && k !== 'campos_alterados' && k !== 'raw');
                        if (keys.length > 0) {
                            datosWrap.classList.remove('d-none');
                            keys.forEach(k => {
                                const tr = document.createElement('tr');
                                const val = typeof parsed[k] === 'object' ? JSON.stringify(parsed[k]) : parsed[k];
                                tr.innerHTML = `
                                    <th style="width: 32%;" class="table-light"><code>${k}</code></th>
                                    <td class="font-monospace">${val ?? '<em>(null)</em>'}</td>
                                `;
                                datosTbody.appendChild(tr);
                            });
                        } else {
                            datosWrap.classList.add('d-none');
                        }
                    }

                    bsJ.show();
                });
            };

            document.querySelectorAll('.btn-ver-json').forEach(btn => {
                window.vincularBotonVerJson(btn);
            });
        }
    });
</script>
