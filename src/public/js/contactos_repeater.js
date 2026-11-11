/**
 * SICM - Repetidor Dinámico 1FN de Vías de Contacto de Paciente
 */
document.addEventListener('DOMContentLoaded', () => {
    const contenedor = document.getElementById('contenedor-contactos-paciente');
    const btnAgregar = document.getElementById('btn-agregar-via-contacto');

    if (btnAgregar && contenedor) {
        btnAgregar.addEventListener('click', () => {
            const primerSelect = contenedor.querySelector('select[name="contactos_tipo[]"]');
            const optionsHtml = primerSelect ? primerSelect.innerHTML : '';

            const fila = document.createElement('div');
            fila.className = 'row g-1 align-items-center fila-contacto mb-1';
            fila.innerHTML = `
                <div class="col-4">
                    <select name="contactos_tipo[]" class="form-select form-select-sm" required style="font-size: 0.78rem;">
                        ${optionsHtml}
                    </select>
                </div>
                <div class="col-4">
                    <input type="text" name="contactos_valor[]" class="form-control form-control-sm font-monospace" placeholder="Ej: 0412-1234567 o correo" required maxlength="100" style="font-size: 0.8rem;">
                </div>
                <div class="col">
                    <input type="text" name="contactos_detalle[]" class="form-control form-control-sm" placeholder="Ej: Personal, Familiar" maxlength="100" style="font-size: 0.78rem;">
                </div>
                <div class="col-auto text-end" style="width: 32px;">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-quitar-fila p-0 d-flex align-items-center justify-content-center" title="Quitar vía" style="width: 28px; height: 28px;">
                        <i class="bi bi-x-lg" style="font-size: 0.72rem;"></i>
                    </button>
                </div>
            `;

            contenedor.appendChild(fila);
            const inputVal = fila.querySelector('input[name="contactos_valor[]"]');
            if (inputVal) {
                inputVal.focus();
            }
        });

        // Delegación de eventos para eliminar fila
        contenedor.addEventListener('click', (e) => {
            const btnQuitar = e.target.closest('.btn-quitar-fila');
            if (btnQuitar) {
                const filas = contenedor.querySelectorAll('.fila-contacto');
                if (filas.length > 1) {
                    btnQuitar.closest('.fila-contacto').remove();
                } else {
                    // Limpiar campos si es la única fila
                    const fila = filas[0];
                    fila.querySelectorAll('input').forEach(inp => inp.value = '');
                }
            }
        });
    }
});
