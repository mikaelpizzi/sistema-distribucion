// src/public/js/validaciones.js
// Validación nativa en cliente (JavaScript Puro / Vanilla JS)
// Objetivo de cátedra: Validar en el navegador para no enviar datos vacíos o inconsistentes a PHP

document.addEventListener('DOMContentLoaded', () => {
    const formCentro = document.getElementById('form-centro');
    if (!formCentro) return;

    formCentro.addEventListener('submit', (e) => {
        const codigo = document.getElementById('codigo_sicm');
        const nombre = document.getElementById('nombre');
        const tipo   = document.getElementById('tipo_centro_id');
        const direccion = document.getElementById('direccion');

        const errores = [];

        // Validación de Código Sanitario SICM
        if (!codigo || codigo.value.trim().length < 3) {
            errores.push("El Código Sanitario (SICM) debe contener al menos 3 caracteres (ej: AMB-MER-001).");
            codigo.classList.add('is-invalid');
        } else {
            codigo.classList.remove('is-invalid');
        }

        // Validación de Nombre del Centro
        if (!nombre || nombre.value.trim().length < 4) {
            errores.push("El nombre del centro de salud debe contener al menos 4 caracteres.");
            nombre.classList.add('is-invalid');
        } else {
            nombre.classList.remove('is-invalid');
        }

        // Validación de Tipo de Centro (Tabla Maestra)
        if (!tipo || !tipo.value || parseInt(tipo.value, 10) <= 0) {
            errores.push("Debe seleccionar una clasificación válida de la tabla maestra.");
            tipo.classList.add('is-invalid');
        } else {
            tipo.classList.remove('is-invalid');
        }

        // Validación de Dirección Física
        if (!direccion || direccion.value.trim().length < 8) {
            errores.push("La dirección física debe ser descriptiva (mínimo 8 caracteres).");
            direccion.classList.add('is-invalid');
        } else {
            direccion.classList.remove('is-invalid');
        }

        // Si existen errores, se cancela el envío a PHP y se muestra feedback en pantalla
        if (errores.length > 0) {
            e.preventDefault();
            const contenedor = document.getElementById('alerta-js');
            if (contenedor) {
                contenedor.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show shadow-sm py-2" role="alert">
                        <strong>Validación en Cliente (JS):</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            ${errores.map(err => `<li>${err}</li>`).join('')}
                        </ul>
                        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                    </div>
                `;
                contenedor.classList.remove('d-none');
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
});

