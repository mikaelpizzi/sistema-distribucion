<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SICM | Catálogo de Medicamentos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f1f3f5;
            font-size: 0.875rem;
            color: #212529;
        }

        .header-slim {
            background-color: #1a202c;
            min-height: 40px;
        }

        .card {
            border-radius: 4px;
        }

        .card-header {
            font-size: 0.875rem;
        }

        .btn-hover-danger:hover {
            color: #dc3545 !important;
        }

        .table > :not(caption) > * > * {
            padding: 0.45rem 0.65rem;
        }

        .atc-tag {
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background-color: #e9ecef;
            color: #212529;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
    </style>
</head>

<body>

    <!-- Header Técnico Delgado (40px) -->
    <header class="header-slim text-white py-1 px-3 d-flex justify-content-between align-items-center shadow-sm">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold tracking-wide">SICM</span>
            <span class="text-white-50">|</span>
            <span class="text-light">Red Municipal de Salud</span>
        </div>
        <div class="text-white-50 font-monospace small" style="font-size: 0.78rem;">
            DB: control_medicamentos_red | Vista: vw_catalogo_medicamentos
        </div>
    </header>

    <!-- Contenido 100% de Ancho (container-fluid) -->
    <div class="container-fluid px-3 py-3">

        <!-- Contenedor de Alertas Dinámicas -->
        <div id="mensajeAlerta"></div>

        <div class="row g-3">
            <!-- COLUMNA IZQUIERDA: Formulario de Registro / Edición -->
            <div class="col-xl-3 col-lg-4 col-md-5">
                <div class="card shadow-sm border">
                    <div class="card-header bg-light text-dark fw-bold border-bottom py-2 px-3" id="tituloFormulario">
                        Registrar Medicamento
                    </div>
                    <div class="card-body p-3">
                        <form id="formMedicamento" novalidate>
                            <input type="hidden" id="med_id" name="id">

                            <div class="mb-2">
                                <label for="codigo_atc" class="form-label small fw-semibold mb-1">Código ATC</label>
                                <input type="text" class="form-control form-control-sm font-monospace" id="codigo_atc" name="codigo_atc"
                                    placeholder="Ej: C09CA01" required maxlength="25">
                            </div>

                            <div class="mb-2">
                                <label for="nombre_generico" class="form-label small fw-semibold mb-1">Nombre Genérico (DCI)</label>
                                <input type="text" class="form-control form-control-sm" id="nombre_generico" name="nombre_generico"
                                    placeholder="Ej: Losartán Potásico" required maxlength="120">
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-semibold mb-1">Concentración</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" id="concentracion_valor" name="concentracion_valor"
                                        placeholder="50, 850, 100" required>
                                    <select class="form-select" id="concentracion_unidad" name="concentracion_unidad" style="max-width: 90px;">
                                        <option value="mg">mg</option>
                                        <option value="mcg">mcg</option>
                                        <option value="g">g</option>
                                        <option value="UI/ml">UI/ml</option>
                                        <option value="UI">UI</option>
                                        <option value="mg/5ml">mg/5ml</option>
                                        <option value="ml">ml</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label for="forma_farmaceutica_id" class="form-label small fw-semibold mb-1">Forma Farmacéutica</label>
                                <select class="form-select form-select-sm" id="forma_farmaceutica_id" name="forma_farmaceutica_id" required>
                                    <option value="">Cargando catálogo...</option>
                                </select>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="requiere_cadena_frio" name="requiere_cadena_frio" value="1">
                                <label class="form-check-label small" for="requiere_cadena_frio">
                                    Requiere Cadena de Frío (2°C - 8°C)
                                </label>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary btn-sm w-100" id="btnGuardar">
                                    Guardar
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm w-50 d-none" id="btnCancelar">
                                    Cancelar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- COLUMNA DERECHA: Catálogo Maestro -->
            <div class="col-xl-9 col-lg-8 col-md-7">
                <div class="card shadow-sm border">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center py-2 px-3 border-bottom">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-dark">Catálogo de Medicamentos</span>
                            <span class="badge bg-secondary rounded-pill" id="contadorRegistros">0</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <!-- Conmutador de Estado (Activos / Papelera para Recuperación) -->
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-sm btn-outline-secondary active" id="btnVerActivos">
                                    Activos
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnVerPapelera">
                                    Desincorporados
                                </button>
                            </div>
                            <input type="text" id="inputBuscar" class="form-control form-control-sm" style="width: 240px;"
                                placeholder="Filtrar por nombre o ATC...">
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-sm align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase" style="font-size: 0.75rem;">
                                    <tr>
                                        <th style="width: 50px;">ID</th>
                                        <th style="width: 120px;">Código ATC</th>
                                        <th>Nombre Genérico</th>
                                        <th style="width: 120px;">Concentración</th>
                                        <th style="width: 160px;">Forma Farmacéutica</th>
                                        <th style="width: 140px;">Refrigeración</th>
                                        <th style="width: 130px;" class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyMedicamentos">
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-3">Cargando catálogo...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <!-- Footer Técnico de Paginación (LIMIT / OFFSET) -->
                    <div class="card-footer bg-light d-flex justify-content-between align-items-center py-2 px-3 border-top">
                        <span class="text-muted small" id="infoPaginacion">Mostrando 0 - 0 de 0</span>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btnPaginaAnt" onclick="paginaAnterior()" disabled>
                                &larr; Anterior
                            </button>
                            <span class="text-muted small font-monospace" id="indicadorPagina">Pág. 1 / 1</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" id="btnPaginaSig" onclick="paginaSiguiente()" disabled>
                                Siguiente &rarr;
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script src="public/js/app.js"></script>
</body>

</html>
