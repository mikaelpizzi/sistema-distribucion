<?php
// src/views/tabs/tab_configuracion.php
// SICM | Módulo Autosuficiente: Identidad Institucional y Parametrización
declare(strict_types=1);

if (!tienePermiso('CONFIG_SISTEMA')) {
    header('Location: index.php');
    exit;
}

$moduloNombre = 'Identidad Institucional';
$seccionNombre = 'Configuración';
?>
<div class="row g-3">
                        <div class="col-xl-9 col-lg-11 mx-auto">
                            <div class="card-sicm">
                                <div class="card-header-sicm d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-building-gear text-primary fs-5"></i>
                                        <span>Identidad Institucional y Configuración de Red</span>
                                    </div>
                                </div>
                                <div class="card-body p-3 p-md-4">
                                    <p class="text-muted small mb-4">
                                        Personalice la identidad gráfica, autoridad sanitaria y umbrales operativos de
                                        rotación de la red. Los cambios se sincronizan de inmediato en los encabezados,
                                        membretes, barra lateral y pantallas de autenticación, registrándose en la bitácora
                                        de auditoría inmutable.
                                    </p>

                                    <form action="procesar.php?accion=guardar_identidad_institucional" method="POST"
                                        enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token"
                                            value="<?= htmlspecialchars(obtenerCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="row g-3 mb-4">
                                            <!-- Nombre Oficial del Sistema -->
                                            <div class="col-md-8">
                                                <label for="nombre_sistema" class="form-label fw-semibold small">Nombre
                                                    Oficial del Sistema <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control form-control-sm" id="nombre_sistema"
                                                    name="nombre_sistema"
                                                    value="<?= htmlspecialchars($configInst['nombre_sistema']) ?>" required
                                                    maxlength="120"
                                                    placeholder="Ej: Sistema Integrado de Control de Medicamentos">
                                                <div class="form-text" style="font-size: 0.74rem;">Título descriptivo de la
                                                    plataforma.</div>
                                            </div>

                                            <!-- Siglas Oficiales -->
                                            <div class="col-md-4">
                                                <label for="siglas_sistema" class="form-label fw-semibold small">Siglas
                                                    Oficiales <span class="text-danger">*</span></label>
                                                <input type="text"
                                                    class="form-control form-control-sm font-monospace text-uppercase"
                                                    id="siglas_sistema" name="siglas_sistema"
                                                    value="<?= htmlspecialchars($configInst['siglas_sistema']) ?>" required
                                                    maxlength="20" placeholder="Ej: SICM">
                                                <div class="form-text" style="font-size: 0.74rem;">Acrónimo para insignias y
                                                    títulos resumidos.</div>
                                            </div>

                                            <!-- Ente Rector -->
                                            <div class="col-md-7">
                                                <label for="ente_rector" class="form-label fw-semibold small">Ente Rector /
                                                    Autoridad Sanitaria <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control form-control-sm" id="ente_rector"
                                                    name="ente_rector"
                                                    value="<?= htmlspecialchars($configInst['ente_rector']) ?>" required
                                                    maxlength="150"
                                                    placeholder="Ej: Dirección de Salud Municipal - Alcaldía de Baruta">
                                                <div class="form-text" style="font-size: 0.74rem;">Órgano institucional
                                                    competente rector de la red.</div>
                                            </div>

                                            <!-- Municipio / Entidad Federal -->
                                            <div class="col-md-5">
                                                <label for="municipio" class="form-label fw-semibold small">Municipio /
                                                    Entidad Federal <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control form-control-sm" id="municipio"
                                                    name="municipio"
                                                    value="<?= htmlspecialchars($configInst['municipio']) ?>" required
                                                    maxlength="100" placeholder="Ej: Municipio Autónomo Baruta">
                                                <div class="form-text" style="font-size: 0.74rem;">Jurisdicción geográfica
                                                    administrativa. Se refleja en membretes institucionales, barra lateral y pantallas de autenticación.</div>
                                            </div>
                                        </div>

                                        <hr class="text-secondary opacity-25 my-4">

                                        <!-- Semáforo y Reglas Operativas FEFO -->
                                        <h6 class="fw-bold text-dark d-flex align-items-center gap-2 mb-3">
                                            <i class="bi bi-clock-history text-warning"></i> Semáforo y Políticas de
                                            Almacenamiento
                                        </h6>
                                        <div class="row g-3 mb-4">
                                            <div class="col-md-6">
                                                <label for="dias_alerta_fefo" class="form-label fw-semibold small">Días de
                                                    Anticipación para Alerta de Caducidad <span class="text-danger">*</span></label>
                                                <div class="input-group input-group-sm">
                                                    <input type="number" class="form-control font-monospace"
                                                        id="dias_alerta_fefo" name="dias_alerta_fefo"
                                                        value="<?= (int) $configInst['dias_alerta_fefo'] ?>" min="1"
                                                        max="365" required>
                                                    <span class="input-group-text">días previos a caducidad</span>
                                                </div>
                                                <div class="form-text" style="font-size: 0.74rem;">
                                                    Los lotes farmacéuticos cuya vigencia sea inferior o igual a este plazo
                                                    activan el semáforo crítico y exigen dispensación prioritaria.
                                                </div>
                                            </div>
                                        </div>

                                        <hr class="text-secondary opacity-25 my-4">

                                        <!-- Logotipo Institucional -->
                                        <h6 class="fw-bold text-dark d-flex align-items-center gap-2 mb-3">
                                            <i class="bi bi-image text-info"></i> Logotipo Institucional
                                        </h6>
                                        <div class="row g-3 mb-4 align-items-center">
                                            <div class="col-md-4 text-center">
                                                <div class="p-3 border rounded bg-light d-flex flex-column align-items-center justify-content-center"
                                                    style="min-height: 120px;">
                                                    <?php if (!empty($configInst['logotipo_ruta'])): ?>
                                                        <img src="<?= htmlspecialchars($configInst['logotipo_ruta']) ?>"
                                                            alt="Logotipo actual" class="img-fluid mb-2"
                                                            style="max-height: 75px; object-fit: contain;">
                                                        <span
                                                            class="badge bg-success-subtle text-success border border-success-subtle"
                                                            style="font-size: 0.7rem;">Logotipo Personalizado</span>
                                                    <?php else: ?>
                                                        <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary bg-opacity-10 text-primary p-2 mb-2"
                                                            style="width: 50px; height: 50px;">
                                                            <i class="bi bi-shield-plus fs-3"></i>
                                                        </div>
                                                        <span class="text-muted small" style="font-size: 0.75rem;">Escudo
                                                            estándar del sistema</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (!empty($configInst['logotipo_ruta'])): ?>
                                                    <div class="form-check text-start mt-2">
                                                        <input class="form-check-input" type="checkbox" id="eliminar_logo"
                                                            name="eliminar_logo" value="1">
                                                        <label class="form-check-label small text-danger" for="eliminar_logo">
                                                            Restablecer escudo predeterminado
                                                        </label>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label fw-semibold small mb-1">Cargar o Reemplazar Logotipo</label>
                                                <!-- Input de archivo oculto -->
                                                <input type="file" class="d-none" id="logotipo_archivo" name="logotipo_archivo"
                                                    accept="image/png,image/jpeg,image/svg+xml,image/webp">

                                                <!-- Zona Drag & Drop -->
                                                <div id="dropzone-logo" class="border border-2 border-dashed rounded-3 p-4 text-center bg-light bg-opacity-50"
                                                    style="border-color: #cbd5e1 !important; cursor: pointer; transition: all 0.2s ease-in-out;">
                                                    <div id="dropzone-content-default">
                                                        <i class="bi bi-cloud-arrow-up text-primary fs-1 d-block mb-1"></i>
                                                        <span class="fw-semibold text-dark d-block mb-1" style="font-size: 0.875rem;">
                                                            Arrastra y suelta el logotipo aquí, o haz clic para seleccionarlo
                                                        </span>
                                                        <span class="text-muted d-block" style="font-size: 0.74rem;">
                                                            Formatos permitidos: PNG, JPG, SVG o WEBP (máximo 2 MB). Se sugiere transparencia.
                                                        </span>
                                                    </div>

                                                    <!-- Previsualización dinámica de archivo seleccionado -->
                                                    <div id="dropzone-preview" class="d-none">
                                                        <div class="d-flex align-items-center justify-content-center gap-3 flex-wrap">
                                                            <img id="preview-img" src="" alt="Previsualización" class="img-fluid rounded border bg-white p-1"
                                                                style="max-height: 75px; max-width: 120px; object-fit: contain;">
                                                            <div class="text-start">
                                                                <div id="preview-filename" class="fw-semibold text-dark text-truncate" style="max-width: 220px; font-size: 0.85rem;"></div>
                                                                <div id="preview-filesize" class="text-muted font-monospace" style="font-size: 0.72rem;"></div>
                                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle mt-1" style="font-size: 0.68rem;">Listo para guardar</span>
                                                            </div>
                                                            <button type="button" class="btn btn-outline-danger btn-sm ms-2" id="btn-cancelar-archivo" title="Cancelar selección">
                                                                <i class="bi bi-trash3 me-1"></i> Quitar
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Barra de Acciones -->
                                        <div
                                            class="d-flex flex-column flex-sm-row justify-content-end align-items-sm-center pt-3 border-top gap-2">
                                            <a href="index.php?tab=centros"
                                                class="btn btn-outline-secondary btn-sm px-3">
                                                Cancelar
                                            </a>
                                            <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold">
                                                <i class="bi bi-check-circle-fill me-1"></i> Guardar Cambios
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.getElementById('dropzone-logo');
    const fileInput = document.getElementById('logotipo_archivo');
    const contentDefault = document.getElementById('dropzone-content-default');
    const contentPreview = document.getElementById('dropzone-preview');
    const previewImg = document.getElementById('preview-img');
    const previewFilename = document.getElementById('preview-filename');
    const previewFilesize = document.getElementById('preview-filesize');
    const btnCancelar = document.getElementById('btn-cancelar-archivo');
    const eliminarLogoCheck = document.getElementById('eliminar_logo');

    if (!dropzone || !fileInput) return;

    // Abrir selector de archivos al hacer clic en la zona
    dropzone.addEventListener('click', (e) => {
        if (e.target.closest('#btn-cancelar-archivo')) return;
        fileInput.click();
    });

    // Eventos Drag & Drop con feedback visual
    ['dragenter', 'dragover'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('bg-primary-subtle');
            dropzone.style.borderColor = '#0d6efd';
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropzone.addEventListener(eventName, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('bg-primary-subtle');
            dropzone.style.borderColor = '#cbd5e1';
        }, false);
    });

    dropzone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files && files.length > 0) {
            fileInput.files = files;
            mostrarPrevisualizacion(files[0]);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files && fileInput.files.length > 0) {
            mostrarPrevisualizacion(fileInput.files[0]);
        }
    });

    function mostrarPrevisualizacion(file) {
        const tiposPermitidos = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];
        if (!tiposPermitidos.includes(file.type)) {
            alert('Formato no permitido. Por favor seleccione un archivo PNG, JPG, SVG o WEBP.');
            limpiarSeleccion();
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            alert('El archivo seleccionado supera el límite de 2 MB.');
            limpiarSeleccion();
            return;
        }

        if (eliminarLogoCheck) {
            eliminarLogoCheck.checked = false;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            previewImg.src = e.target.result;
            previewFilename.textContent = file.name;
            const sizeKB = file.size / 1024;
            previewFilesize.textContent = sizeKB > 1024 ? (sizeKB / 1024).toFixed(2) + ' MB' : sizeKB.toFixed(1) + ' KB';
            contentDefault.classList.add('d-none');
            contentPreview.classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    }

    function limpiarSeleccion() {
        fileInput.value = '';
        previewImg.src = '';
        previewFilename.textContent = '';
        previewFilesize.textContent = '';
        contentPreview.classList.add('d-none');
        contentDefault.classList.remove('d-none');
    }

    if (btnCancelar) {
        btnCancelar.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            limpiarSeleccion();
        });
    }

    if (eliminarLogoCheck) {
        eliminarLogoCheck.addEventListener('change', () => {
            if (eliminarLogoCheck.checked) {
                limpiarSeleccion();
            }
        });
    }
});
</script>
