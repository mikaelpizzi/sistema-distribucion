// src/public/js/app.js
// Controlador Frontend en JavaScript Puro (Vanilla JS)
// Implementa Paginación Server-Side (LIMIT/OFFSET) y Búsqueda con Debounce (300ms)

let listaMedicamentos = [];
let estadoActual = 'activos'; // 'activos' o 'papelera'
let paginaActual = 1;
let totalPaginas = 1;
let totalRegistros = 0;
let terminoBusqueda = '';
let timerDebounce = null;

document.addEventListener('DOMContentLoaded', () => {
    cargarFormasFarmaceuticas();
    cargarMedicamentos();

    // Eventos de formulario y cancelación
    document.getElementById('formMedicamento').addEventListener('submit', guardarMedicamento);
    document.getElementById('btnCancelar').addEventListener('click', limpiarFormulario);

    // Búsqueda con Debounce de 300ms (Evita saturar el motor MariaDB en cada tecla)
    document.getElementById('inputBuscar').addEventListener('input', (e) => {
        clearTimeout(timerDebounce);
        timerDebounce = setTimeout(() => {
            terminoBusqueda = e.target.value.trim();
            paginaActual = 1; // Reiniciar a la primera página con cada nueva búsqueda
            cargarMedicamentos();
        }, 300);
    });

    // Conmutadores de Estado (Activos / Papelera)
    document.getElementById('btnVerActivos').addEventListener('click', () => cambiarEstado('activos'));
    document.getElementById('btnVerPapelera').addEventListener('click', () => cambiarEstado('papelera'));
});

// 1. Alternar entre catálogo activo y papelera de desincorporados
function cambiarEstado(nuevoEstado) {
    if (estadoActual === nuevoEstado) return;
    estadoActual = nuevoEstado;
    paginaActual = 1; // Reiniciar página

    const btnActivos = document.getElementById('btnVerActivos');
    const btnPapelera = document.getElementById('btnVerPapelera');

    if (estadoActual === 'activos') {
        btnActivos.classList.add('active');
        btnPapelera.classList.remove('active');
    } else {
        btnPapelera.classList.add('active');
        btnActivos.classList.remove('active');
    }
    cargarMedicamentos();
}

// 2. Cargar opciones del catálogo de formas farmacéuticas (3FN)
function cargarFormasFarmaceuticas() {
    fetch('controllers/api.php?accion=formas')
        .then(res => res.json())
        .then(datos => {
            if (datos.ok) {
                const select = document.getElementById('forma_farmaceutica_id');
                select.innerHTML = '<option value="">-- Seleccionar --</option>';
                datos.datos.forEach(f => {
                    select.innerHTML += `<option value="${f.id}">${f.nombre} (${f.unidad_base})</option>`;
                });
            }
        })
        .catch(() => mostrarAlerta('Error al cargar catálogo de formas farmacéuticas.', 'danger'));
}

// 3. READ: Consultar medicamentos paginados y filtrados en servidor (Server-Side)
function cargarMedicamentos() {
    const url = `controllers/api.php?accion=listar&estado=${estadoActual}&pagina=${paginaActual}&q=${encodeURIComponent(terminoBusqueda)}`;

    fetch(url)
        .then(res => res.json())
        .then(datos => {
            if (datos.ok) {
                listaMedicamentos = datos.datos;
                const pag = datos.paginacion;
                paginaActual = pag.pagina_actual;
                totalPaginas = pag.total_paginas;
                totalRegistros = pag.total_registros;

                renderizarTabla(listaMedicamentos);
                actualizarPaginacion(pag);
            } else {
                document.getElementById('tbodyMedicamentos').innerHTML =
                    `<tr><td colspan="7" class="text-center text-danger py-3">${datos.error}</td></tr>`;
            }
        })
        .catch(() => {
            document.getElementById('tbodyMedicamentos').innerHTML =
                '<tr><td colspan="7" class="text-center text-danger py-3">Error de comunicación con el servidor.</td></tr>';
        });
}

// 4. Actualizar controles de paginación en el footer de la tabla
function actualizarPaginacion(pag) {
    const contador = document.getElementById('contadorRegistros');
    const info = document.getElementById('infoPaginacion');
    const indicador = document.getElementById('indicadorPagina');
    const btnAnt = document.getElementById('btnPaginaAnt');
    const btnSig = document.getElementById('btnPaginaSig');

    contador.textContent = pag.total_registros;
    indicador.textContent = `Pág. ${pag.pagina_actual} / ${pag.total_paginas}`;

    if (pag.total_registros === 0) {
        info.textContent = 'Mostrando 0 de 0 registros';
    } else {
        const inicio = (pag.pagina_actual - 1) * pag.por_pagina + 1;
        const fin = Math.min(inicio + listaMedicamentos.length - 1, pag.total_registros);
        info.textContent = `Mostrando ${inicio} - ${fin} de ${pag.total_registros} registros`;
    }

    btnAnt.disabled = (pag.pagina_actual <= 1);
    btnSig.disabled = (pag.pagina_actual >= pag.total_paginas);
}

// 5. Navegación entre páginas
function paginaAnterior() {
    if (paginaActual > 1) {
        paginaActual--;
        cargarMedicamentos();
    }
}

function paginaSiguiente() {
    if (paginaActual < totalPaginas) {
        paginaActual++;
        cargarMedicamentos();
    }
}

// 6. Renderizado denso y limpio de la tabla
function renderizarTabla(medicamentos) {
    const tbody = document.getElementById('tbodyMedicamentos');

    if (medicamentos.length === 0) {
        const msg = terminoBusqueda !== ''
            ? `No se encontraron resultados para "${terminoBusqueda}".`
            : (estadoActual === 'activos' ? 'No hay medicamentos registrados en el catálogo activo.' : 'La papelera de desincorporados está vacía.');
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-3">${msg}</td></tr>`;
        return;
    }

    tbody.innerHTML = medicamentos.map(m => {
        // Refrigeración silenciosa: solo etiqueta si es SÍ, guion suave si es NO
        const badgeFrio = m.requiere_cadena_frio == 1
            ? '<span class="badge bg-info text-dark fw-semibold" style="font-size: 0.75rem;">❄ 2°C - 8°C</span>'
            : '<span class="text-muted">—</span>';

        // Acciones horizontales según el estado
        let botonesAccion = '';
        if (estadoActual === 'activos') {
            botonesAccion = `
                <div class="d-flex justify-content-end align-items-center gap-2">
                    <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick="prepararEdicion(${m.id})">Editar</button>
                    <button class="btn btn-link text-muted btn-hover-danger p-0 small text-decoration-none" onclick="eliminarMedicamento(${m.id})" title="Desincorporar">Eliminar</button>
                </div>
            `;
        } else {
            botonesAccion = `
                <div class="d-flex justify-content-end align-items-center">
                    <button class="btn btn-outline-success btn-sm py-0 px-2" onclick="restaurarMedicamento(${m.id})">Restaurar</button>
                </div>
            `;
        }

        return `
            <tr>
                <td class="text-muted">#${m.id}</td>
                <td><span class="atc-tag border px-2 py-0 rounded">${m.codigo_atc}</span></td>
                <td class="fw-semibold text-dark text-nowrap">${m.nombre_generico}</td>
                <td>${m.concentracion}</td>
                <td class="text-nowrap"><span class="badge bg-light text-secondary border">${m.forma_nombre}</span></td>
                <td>${badgeFrio}</td>
                <td class="text-end">${botonesAccion}</td>
            </tr>
        `;
    }).join('');
}

// 7. CREATE / UPDATE: Guardar medicamento
function guardarMedicamento(e) {
    e.preventDefault();

    const codigo = document.getElementById('codigo_atc').value.trim();
    const nombre = document.getElementById('nombre_generico').value.trim();
    const valorConc = document.getElementById('concentracion_valor').value.trim();
    const forma = document.getElementById('forma_farmaceutica_id').value;

    if (!codigo || !nombre || !valorConc || !forma) {
        mostrarAlerta('Por favor complete todos los campos requeridos.', 'danger');
        return;
    }

    const formData = new FormData(document.getElementById('formMedicamento'));

    fetch('controllers/api.php?accion=guardar', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(datos => {
        if (datos.ok) {
            mostrarAlerta(datos.mensaje, 'success');
            limpiarFormulario();
            if (estadoActual !== 'activos') {
                cambiarEstado('activos');
            } else {
                cargarMedicamentos();
            }
        } else {
            mostrarAlerta(datos.error, 'danger');
        }
    })
    .catch(() => mostrarAlerta('Error de procesamiento en el servidor PHP.', 'danger'));
}

// 8. Preparar edición
function prepararEdicion(id) {
    const med = listaMedicamentos.find(m => m.id == id);
    if (!med) return;

    document.getElementById('med_id').value = med.id;
    document.getElementById('codigo_atc').value = med.codigo_atc;
    document.getElementById('nombre_generico').value = med.nombre_generico;
    document.getElementById('forma_farmaceutica_id').value = med.forma_farmaceutica_id;
    document.getElementById('requiere_cadena_frio').checked = (med.requiere_cadena_frio == 1);

    // Separar número y unidad de concentración (ej. "50mg" -> 50 y "mg")
    const match = (med.concentracion || '').match(/^([0-9.]+)\s*(.*)$/);
    if (match) {
        document.getElementById('concentracion_valor').value = match[1];
        if (match[2]) {
            document.getElementById('concentracion_unidad').value = match[2];
        }
    } else {
        document.getElementById('concentracion_valor').value = med.concentracion;
    }

    document.getElementById('tituloFormulario').textContent = `Editar Medicamento #${med.id}`;
    document.getElementById('btnGuardar').textContent = 'Guardar Cambios';
    document.getElementById('btnCancelar').classList.remove('d-none');
}

// 9. DELETE: Borrado Lógico (Soft Delete)
function eliminarMedicamento(id) {
    if (!confirm('¿Confirma que desea desincorporar este medicamento del catálogo activo?')) {
        return;
    }

    const formData = new FormData();
    formData.append('id', id);

    fetch('controllers/api.php?accion=eliminar', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(datos => {
        if (datos.ok) {
            mostrarAlerta(datos.mensaje, 'warning');
            cargarMedicamentos();
        } else {
            mostrarAlerta(datos.error, 'danger');
        }
    });
}

// 10. RESTORE: Recuperar medicamento de la papelera
function restaurarMedicamento(id) {
    if (!confirm('¿Desea restaurar este medicamento al catálogo activo?')) {
        return;
    }

    const formData = new FormData();
    formData.append('id', id);

    fetch('controllers/api.php?accion=restaurar', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(datos => {
        if (datos.ok) {
            mostrarAlerta(datos.mensaje, 'success');
            cargarMedicamentos();
        } else {
            mostrarAlerta(datos.error, 'danger');
        }
    });
}

// 11. Restaurar formulario
function limpiarFormulario() {
    document.getElementById('formMedicamento').reset();
    document.getElementById('med_id').value = '';
    document.getElementById('tituloFormulario').textContent = 'Registrar Medicamento';
    document.getElementById('btnGuardar').textContent = 'Guardar';
    document.getElementById('btnCancelar').classList.add('d-none');
}

// 12. Notificaciones
function mostrarAlerta(mensaje, tipo) {
    const contenedor = document.getElementById('mensajeAlerta');
    contenedor.innerHTML = `
        <div class="alert alert-${tipo} alert-dismissible fade show py-2 shadow-sm" role="alert">
            ${mensaje}
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
    `;
    setTimeout(() => {
        contenedor.innerHTML = '';
    }, 4500);
}
