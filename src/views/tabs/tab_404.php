<?php
// src/views/tabs/tab_404.php
// SICM | Vista de Módulo No Localizado
declare(strict_types=1);

$moduloNombre = 'Módulo No Localizado';
$seccionNombre = 'Navegación';
?>
<div class="row justify-content-center my-4">
                        <div class="col-xl-7 col-lg-9 text-center">
                            <div class="card bg-white border rounded-3 p-4 p-md-5 shadow-sm">
                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-warning bg-opacity-10 text-warning p-3 mb-3 mx-auto"
                                    style="width: 68px; height: 68px;">
                                    <i class="bi bi-shield-exclamation fs-2 text-warning"></i>
                                </div>
                                <h4 class="fw-bold text-dark mb-2">Módulo No Localizado</h4>
                                <div
                                    class="alert alert-warning border text-start d-flex align-items-center gap-2 mb-4 py-2.5 px-3 small">
                                    <i class="bi bi-exclamation-triangle-fill fs-5 text-warning flex-shrink-0"></i>
                                    <div>
                                        La sección o módulo solicitado
                                        ('<strong><?= htmlspecialchars($tabActiva) ?></strong>') no está disponible o no
                                        existe.
                                    </div>
                                </div>
                                <p class="text-muted small mb-4" style="line-height: 1.6;">
                                    El enlace seleccionado no corresponde a ninguno de los subsistemas o catálogos
                                    operativos del sistema de salud. Puede reanudar sus operaciones habituales regresando a
                                    la red de ambulatorios.
                                </p>
                                <div class="d-flex justify-content-center gap-2">
                                    <a href="index.php?tab=centros"
                                        class="btn btn-primary btn-sm px-4 fw-semibold d-inline-flex align-items-center gap-2">
                                        <i class="bi bi-hospital"></i> Ir a Ambulatorios
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
