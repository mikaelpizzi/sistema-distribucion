/**
 * SICM - Repetidor Dinámico 1FN de Vías de Contacto de Paciente
 * Normalización y formateo en tiempo real (Teléfonos y Correos)
 */
document.addEventListener('DOMContentLoaded', () => {
    const contenedor = document.getElementById('contenedor-contactos-paciente');
    const btnAgregar = document.getElementById('btn-agregar-via-contacto');

    if (!contenedor) return;

    /**
     * Determina si el tipo de contacto seleccionado corresponde a Correo Electrónico
     */
    function esTipoEmail(selectEl) {
        if (!selectEl) return false;
        const opt = selectEl.options[selectEl.selectedIndex];
        const codigo = opt ? (opt.dataset.codigo || opt.getAttribute('data-codigo') || '').toUpperCase() : '';
        const texto = opt ? opt.textContent.toUpperCase() : '';
        const val = String(selectEl.value || '');
        return val === '4' || codigo === 'EMAIL_ALTERNO' || codigo.includes('EMAIL') || texto.includes('CORREO');
    }

    /**
     * Formatea un número telefónico al formato canónico 04XX-XXXXXXX (máx 11 dígitos, 12 caracteres)
     */
    function formatearTelefono(valor) {
        let digits = valor.replace(/\D/g, '');
        if (digits.length > 11) {
            digits = digits.slice(0, 11);
        }
        if (digits.length > 4) {
            return digits.slice(0, 4) + '-' + digits.slice(4);
        }
        return digits;
    }

    /**
     * Configura el comportamiento reactivo de una fila de contacto (tipo, placeholder y formateo)
     */
    function configurarFilaContacto(fila) {
        const select = fila.querySelector('select[name="contactos_tipo[]"]');
        const inputVal = fila.querySelector('input[name="contactos_valor[]"]');
        if (!select || !inputVal) return;

        function actualizarSegunTipo() {
            const esEmail = esTipoEmail(select);
            const opt = select.options[select.selectedIndex];
            const codigo = opt ? (opt.dataset.codigo || opt.getAttribute('data-codigo') || '').toUpperCase() : '';

            if (esEmail) {
                inputVal.type = 'email';
                inputVal.placeholder = 'ejemplo@correo.com';
                inputVal.maxLength = 100;
            } else {
                inputVal.type = 'text';
                inputVal.placeholder = (codigo === 'TEL_FIJO') ? '0274-1234567' : '0414-1234567';
                inputVal.maxLength = 12; // 11 dígitos + 1 guión
                if (inputVal.value.trim() !== '') {
                    inputVal.value = formatearTelefono(inputVal.value);
                }
            }
        }

        select.addEventListener('change', () => {
            actualizarSegunTipo();
            inputVal.classList.remove('is-invalid');
        });

        inputVal.addEventListener('input', () => {
            inputVal.classList.remove('is-invalid');
            if (!esTipoEmail(select)) {
                const prev = inputVal.value;
                const formatted = formatearTelefono(prev);
                if (formatted !== prev) {
                    inputVal.value = formatted;
                }
            }
        });

        actualizarSegunTipo();
    }

    // Inicializar filas existentes
    contenedor.querySelectorAll('.fila-contacto').forEach(configurarFilaContacto);

    if (btnAgregar) {
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
                    <input type="text" name="contactos_valor[]" class="form-control form-control-sm font-monospace" placeholder="0414-1234567" required maxlength="12" style="font-size: 0.8rem;">
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
            configurarFilaContacto(fila);

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
                    fila.querySelectorAll('input').forEach(inp => {
                        inp.value = '';
                        inp.classList.remove('is-invalid');
                    });
                }
            }
        });
    }
});
