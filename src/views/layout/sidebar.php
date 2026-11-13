<aside class="sicm-sidebar offcanvas-lg offcanvas-start" id="sidebarMenu" tabindex="-1"
            aria-labelledby="sidebarMenuLabel">
            <!-- Encabezado del Sidebar: Identidad Institucional -->
            <div class="sicm-sidebar-header d-flex align-items-center justify-content-between">
                <a href="index.php" class="sicm-sidebar-brand">
                    <?php if (!empty($configInst['logotipo_ruta'])): ?>
                        <img src="<?= htmlspecialchars($configInst['logotipo_ruta']) ?>" alt="Logo"
                            class="sicm-sidebar-logo">
                    <?php else: ?>
                        <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"
                            style="width: 36px; height: 36px; flex-shrink: 0;">
                            <i class="bi bi-shield-plus fs-5 text-primary"></i>
                        </div>
                    <?php endif; ?>
                    <div class="d-flex flex-column text-truncate" style="min-width: 0;">
                        <span class="brand-title text-truncate"
                            style="font-size: 0.95rem; line-height: 1.25;"><?= htmlspecialchars($configInst['siglas_sistema']) ?></span>
                        <span class="text-muted text-truncate" style="font-size: 0.68rem; line-height: 1.25;"
                            title="<?= htmlspecialchars($configInst['ente_rector']) ?>"><?= htmlspecialchars($configInst['ente_rector']) ?></span>
                    </div>
                </a>
                <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas"
                    data-bs-target="#sidebarMenu" aria-label="Cerrar"></button>
            </div>

            <!-- Enlaces agrupados semánticamente con RBAC Dinámico -->
            <div class="sicm-sidebar-nav">
                <!-- GESTIÓN CLÍNICA -->
                <?php if ((tienePermiso('PACIENTES_VER') && esModuloActivo('pacientes')) || (tienePermiso('RECIPES_VER') && esModuloActivo('recipes')) || (tienePermiso('DESP_VER') && esModuloActivo('despachos'))): ?>
                    <div class="sidebar-section-title">
                        <i class="bi bi-heart-pulse"></i> Gestión Clínica
                    </div>
                    <?php if (tienePermiso('PACIENTES_VER') && esModuloActivo('pacientes')): ?>
                        <a href="index.php?tab=pacientes"
                            class="sidebar-nav-item <?= $tabActiva === 'pacientes' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-people nav-icon"></i>
                                <span class="text-truncate">Pacientes</span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('RECIPES_VER') && esModuloActivo('recipes')): ?>
                        <a href="index.php?tab=recipes"
                            class="sidebar-nav-item <?= $tabActiva === 'recipes' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-file-earmark-medical nav-icon"></i>
                                <span class="text-truncate">Récipes Médicos</span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('DESP_VER') && esModuloActivo('despachos')): ?>
                        <a href="index.php?tab=despachos"
                            class="sidebar-nav-item <?= $tabActiva === 'despachos' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-box-seam nav-icon"></i>
                                <span class="text-truncate">Dispensación FEFO</span>
                            </span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- VADEMÉCUM Y RED -->
                <?php if ((tienePermiso('MED_VER') && esModuloActivo('medicamentos')) || (tienePermiso('CENTROS_VER') && esModuloActivo('centros'))): ?>
                    <div class="sidebar-section-title mt-3">
                        <i class="bi bi-capsule"></i> Vademécum y Red
                    </div>
                    <?php if (tienePermiso('MED_VER') && esModuloActivo('medicamentos')): ?>
                        <a href="index.php?tab=medicamentos"
                            class="sidebar-nav-item <?= $tabActiva === 'medicamentos' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-prescription2 nav-icon"></i>
                                <span class="text-truncate">Vademécum</span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('CENTROS_VER') && esModuloActivo('centros')): ?>
                        <a href="index.php?tab=centros"
                            class="sidebar-nav-item <?= $tabActiva === 'centros' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-hospital nav-icon"></i>
                                <span
                                    class="text-truncate"><?= $puedeVerTodaLaRed ? 'Red de Ambulatorios' : 'Mi Ambulatorio' ?></span>
                            </span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- ADMINISTRACIÓN Y CONTROL -->
                <?php if ((tienePermiso('MAESTRAS_GESTIONAR') && esModuloActivo('maestras')) || (tienePermiso('CONFIG_SISTEMA') && esModuloActivo('configuracion')) || (tienePermiso('USR_VER') && esModuloActivo('usuarios')) || (tienePermiso('AUDIT_VER') && esModuloActivo('auditoria'))): ?>
                    <div class="sidebar-section-title mt-3">
                        <i class="bi bi-shield-check"></i> Administración Central
                    </div>
                    <?php if (tienePermiso('MAESTRAS_GESTIONAR') && esModuloActivo('maestras')): ?>
                        <a href="index.php?tab=maestras"
                            class="sidebar-nav-item <?= $tabActiva === 'maestras' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-sliders nav-icon"></i>
                                <span class="text-truncate">Catálogos Maestros</span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('CONFIG_SISTEMA') && esModuloActivo('configuracion')): ?>
                        <a href="index.php?tab=configuracion"
                            class="sidebar-nav-item <?= $tabActiva === 'configuracion' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-building-gear nav-icon"></i>
                                <span class="text-truncate">Identidad Institucional</span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('USR_VER') && esModuloActivo('usuarios')): ?>
                        <a href="index.php?tab=usuarios"
                            class="sidebar-nav-item <?= $tabActiva === 'usuarios' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-person-badge nav-icon"></i>
                                <span class="text-truncate">Operadores</span>
                            </span>
                        </a>
                    <?php endif; ?>
                    <?php if (tienePermiso('AUDIT_VER') && esModuloActivo('auditoria')): ?>
                        <a href="index.php?tab=auditoria"
                            class="sidebar-nav-item <?= $tabActiva === 'auditoria' ? 'active' : '' ?>">
                            <span class="d-flex align-items-center gap-2 text-truncate">
                                <i class="bi bi-journal-text nav-icon"></i>
                                <span class="text-truncate">Auditoría Forense</span>
                            </span>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Pie del Sidebar Institucional (Sticky Bottom) -->
            <div class="sicm-sidebar-footer">
                <?php
                $rolNombreKey = (string)($usuario['rol_nombre'] ?? '');
                $rolLabel = match ($rolNombreKey) {
                    'rol_admin' => 'Administrador Central',
                    'rol_farmaceuta' => 'Farmacéutico Operador',
                    'rol_auditor' => 'Auditor Sanitario',
                    default => ucwords(str_replace(['rol_', '_'], ['', ' '], $rolNombreKey ?: 'Operador'))
                };
                ?>
                <div class="sidebar-user-card">
                    <div class="user-name text-truncate"><?= htmlspecialchars($usuario['nombre']) ?></div>
                    <div class="user-role text-truncate"><?= htmlspecialchars($rolLabel) ?></div>
                    <div class="user-center text-truncate">
                        <i
                            class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($puedeVerTodaLaRed ? 'Sede Central Municipal' : ($usuario['centro_nombre'] ?? 'Red Municipal')) ?>
                    </div>
                </div>

                <?php if (tienePermiso('SISTEMA_RESPALDO')): ?>
                    <form action="procesar.php" method="POST" class="mb-2">
                        <input type="hidden" name="csrf_token"
                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="accion" value="descargar_respaldo">
                        <button type="submit"
                            class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center py-1.5"
                            style="font-size: 0.74rem; font-weight: 600;"
                            title="Descargar snapshot caliente comprimido (.sql.gz)">
                            <i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Crear Respaldo
                        </button>
                    </form>
                <?php endif; ?>

                <a href="logout.php"
                    class="btn btn-outline-danger btn-sm w-100 d-flex align-items-center justify-content-center py-1.5"
                    style="font-size: 0.76rem;" title="Cerrar sesión de forma segura">
                    <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                </a>
            </div>
        </aside>
