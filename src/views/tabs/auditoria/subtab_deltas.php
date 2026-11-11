<?php
// src/views/tabs/auditoria/subtab_deltas.php
// SICM | Subpestaña: Historial de Modificaciones Celda por Celda (Deltas)
declare(strict_types=1);
?>
<div class="card-sicm shadow-sm" id="forensic-table-container" data-subtab="deltas"
    data-page="<?= $paginaActual ?>" data-max-id="<?= $maxDeltaId ?>"
    data-has-filters="<?= $hayFiltrosActivos ? '1' : '0' ?>">
    <div class="card-header-sicm d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-clock-history text-primary"></i>
            <span class="fw-bold">Historial de Modificaciones</span>
            <span class="text-muted small fw-normal">(Mostrando <?= count($deltas) ?> de
                <?= number_format($totalFiltrados) ?> registros)</span>
        </div>
        <!-- Filtro Específico de Deltas: Campo Modificado -->
        <div class="d-flex align-items-center gap-2">
            <form method="GET" action="index.php" class="d-flex align-items-center gap-1 m-0">
                <input type="hidden" name="tab" value="auditoria">
                <input type="hidden" name="subtab" value="deltas">
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
                <label for="subtab-campo-filtro" class="text-muted small fw-semibold me-1 d-none d-sm-inline" style="font-size: 0.75rem;">Campo:</label>
                <select name="campo_filtro" id="subtab-campo-filtro" class="form-select form-select-sm" style="font-size: 0.75rem; width: auto;" onchange="this.form.submit()">
                    <option value="todos">-- Todos los campos --</option>
                    <?php foreach ($camposModificadosUnicos as $cmp): ?>
                        <option value="<?= htmlspecialchars($cmp) ?>" <?= $filtroCampo === $cmp ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cmp) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filtroCampo !== 'todos' && $filtroCampo !== ''): ?>
                    <a href="index.php?tab=auditoria&subtab=deltas<?= $filtroTabla !== 'todas' ? '&tabla_filtro=' . urlencode($filtroTabla) : '' ?>" class="btn btn-outline-danger btn-sm py-0.5 px-1.5" title="Quitar filtro de campo">
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
                    <th style="width: 140px;">Fecha</th>
                    <th style="width: 190px;">Elemento modificado</th>
                    <th style="width: 150px;">Campo</th>
                    <th>Cambio realizado</th>
                    <th style="width: 170px;">Canal / Conexión</th>
                    <th>Modificado por</th>
                </tr>
            </thead>
            <tbody id="forensic-tbody">
                <?php if (empty($deltas)): ?>
                    <tr class="fila-sin-datos">
                        <td colspan="7" class="text-center py-5">
                            <div class="py-3">
                                <div class="mb-2 text-muted">
                                    <i class="bi bi-search text-secondary" style="font-size: 2.2rem;"></i>
                                </div>
                                <h6 class="text-secondary fw-semibold mb-1">No se encontraron eventos que
                                    coincidan
                                    con los criterios de búsqueda seleccionados.</h6>
                                <p class="text-muted small mb-3">Intenta cambiar los términos de búsqueda,
                                    rango de
                                    fechas o restablece los filtros para ver todo el historial de cambios.
                                </p>
                                <a href="index.php?tab=auditoria&subtab=deltas"
                                    class="btn btn-outline-primary btn-sm px-3 shadow-xs">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Restablecer todos los
                                    filtros
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deltas as $d): ?>
                        <?php
                        $esDirecto = ($d['ip_origen'] === 'CONSOLA_DIRECTA' || $d['usuario_app_id'] === null);
                        $rowClass = $esDirecto ? 'table-danger-subtle border-start border-4 border-danger' : 'border-start border-3 border-light';
                        $entidadMod = resolverEntidadLegible($d['tabla_nombre'] ?? '', (int) $d['registro_id'], [], $diccionariosEntidades);
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <td class="code-tag">#<?= (int) $d['id'] ?></td>
                            <td class="small font-monospace"><?= htmlspecialchars($d['created_at']) ?></td>
                            <td>
                                <span
                                    class="badge bg-light text-dark border"><?= htmlspecialchars($entidadMod['entidad']) ?></span>
                                <span class="code-tag">ID: #<?= (int) $d['registro_id'] ?></span>
                                <?php
                                $nomDesc = $entidadMod['nombre_descriptivo'] ?: ($entidadMod['nombre_corto'] ?? '');
                                if (!empty($nomDesc)):
                                    $tooltipCompleto = $entidadMod['entidad'] . ': ' . $nomDesc . ' [ID: #' . (int) $d['registro_id'] . ']';
                                    ?>
                                    <div class="text-dark small fw-semibold text-truncate mt-1"
                                        style="max-width: 220px; font-size: 0.76rem;"
                                        title="<?= htmlspecialchars($tooltipCompleto) ?>">
                                        <i
                                            class="bi bi-tag-fill me-1 text-primary opacity-75"></i><?= htmlspecialchars($nomDesc) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code class="fw-semibold"><?= htmlspecialchars($d['campo_modificado']) ?></code>
                            </td>
                            <td>
                                <div class="d-flex align-items-center flex-wrap gap-1 font-monospace"
                                    style="font-size: 0.8rem;">
                                    <?php if ($d['valor_anterior'] === null): ?>
                                        <del
                                            class="text-danger bg-danger-subtle px-1 rounded text-decoration-line-through"><em>(Vacío)</em></del>
                                    <?php else: ?>
                                        <del
                                            class="text-danger bg-danger-subtle px-1 rounded text-decoration-line-through"><?= htmlspecialchars($d['valor_anterior']) ?></del>
                                    <?php endif; ?>

                                    <i class="bi bi-arrow-right text-muted mx-1"></i>

                                    <?php if ($d['valor_nuevo'] === null): ?>
                                        <ins
                                            class="text-success fw-bold bg-success-subtle px-1 rounded text-decoration-none"><em>(Vacío)</em></ins>
                                    <?php else: ?>
                                        <ins
                                            class="text-success fw-bold bg-success-subtle px-1 rounded text-decoration-none"><?= htmlspecialchars($d['valor_nuevo']) ?></ins>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($d['ip_origen'] === 'CONSOLA_DIRECTA'): ?>
                                    <span class="badge bg-danger text-white font-monospace"
                                        title="Modificación directa en base de datos">
                                        <i class="bi bi-terminal-fill me-1"></i> CLI /
                                        <?= htmlspecialchars($d['db_user']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-light text-secondary border font-monospace"
                                        title="Modificación desde aplicación web">
                                        <i class="bi bi-globe me-1"></i> Web
                                        [<?= htmlspecialchars($d['ip_origen']) ?>]
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($d['usuario_app_id'] !== null): ?>
                                    <div class="fw-semibold text-dark">
                                        <?= htmlspecialchars($d['operador_app']) ?>
                                        <?php if (!empty($d['email_app'])): ?>
                                            <span
                                                class="text-muted fw-normal small">(<?= htmlspecialchars($d['email_app']) ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-secondary small" style="font-size: 0.72rem;">
                                        <?= htmlspecialchars($d['rol_app'] ?? 'Usuario') ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger fw-bold">
                                        <i class="bi bi-exclamation-octagon-fill me-1"></i> Sin sesión de usuario
                                    </span>
                                    <div class="text-muted font-monospace small" style="font-size: 0.70rem;">
                                        <?= htmlspecialchars($d['db_user']) ?>
                                    </div>
                                <?php endif; ?>
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
                Mostrando <strong><?= count($deltas) ?></strong> de
                <strong><?= number_format($totalFiltrados) ?></strong> modificaciones (Página
                <?= $paginaActual ?>
                de <?= $totalPaginas ?>)
            </span>
            <div class="btn-group btn-group-sm">
                <?php
                $queryParams = "&subtab=deltas&tabla_filtro=" . urlencode($filtroTabla)
                    . "&usuario_filtro=" . urlencode($filtroUsuario)
                    . "&campo_filtro=" . urlencode($filtroCampo)
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
