// src/public/js/validaciones.js
// Validación nativa en cliente (JavaScript Puro / Vanilla JS)
// Objetivo de cátedra: Validar en el navegador para no enviar datos vacíos o inconsistentes a PHP
// Reglas: Cero frameworks, sanitización en tiempo real (mayúsculas/slugs) y protección de tipos.

document.addEventListener('DOMContentLoaded', () => {

    // =========================================================================
    // 1. SANITIZACIÓN EN TIEMPO REAL (Evitar datos inconsistentes)
    // =========================================================================

    // Código SICM en Formulario Principal: Mayúsculas y sin espacios descontrolados
    const codigoSicm = document.getElementById('codigo_sicm');
    if (codigoSicm) {
        codigoSicm.addEventListener('input', () => {
            codigoSicm.value = codigoSicm.value.toUpperCase().replace(/\s+/g, '-');
        });
    }

    // Códigos en Tablas Maestras (Slugs canónicos: SNAKE_CASE, mayúsculas)
    document.querySelectorAll('input[name="codigo"]').forEach(input => {
        input.addEventListener('input', () => {
            input.value = input.value.toUpperCase().replace(/[^A-Z0-9_]/g, '_');
        });
    });

    // =========================================================================
    // 2. VALIDACIÓN DEL FORMULARIO DE AMBULATORIOS
    // =========================================================================
    const formCentro = document.getElementById('form-centro');
    if (formCentro) {
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
    }

    // =========================================================================
    // 3. VALIDACIÓN Y DINÁMICA DEL FORMULARIO DE OPERADORES (USUARIOS)
    // =========================================================================
    const formUsuario = document.getElementById('form-usuario');
    if (formUsuario) {
        const rolSelect = document.getElementById('u_rol_id');
        const wrapCentro = document.getElementById('wrap_centro_salud');
        const centroSelect = document.getElementById('u_centro_salud_id');
        const passInput = document.getElementById('u_password');
        const idInput = document.getElementById('u_id');
        const emailInput = document.getElementById('u_email');
        const nombreInput = document.getElementById('u_nombre');

        const actualizarJurisdiccion = () => {
            if (!rolSelect || !wrapCentro || !centroSelect) return;
            const rolVal = parseInt(rolSelect.value, 10);
            if (rolVal === 2) {
                // Rol Farmaceuta: Requiere obligatoriamente ambulatorio
                wrapCentro.classList.remove('d-none');
                centroSelect.setAttribute('required', 'required');
            } else {
                // Rol Administrador: Jurisdicción central global (NULL)
                wrapCentro.classList.add('d-none');
                centroSelect.removeAttribute('required');
                centroSelect.value = '';
                centroSelect.classList.remove('is-invalid');
            }
        };

        if (rolSelect) {
            rolSelect.addEventListener('change', actualizarJurisdiccion);
            actualizarJurisdiccion();
        }

        formUsuario.addEventListener('submit', (e) => {
            const erroresU = [];
            const esEdicion = idInput && idInput.value.trim() !== '';

            if (!nombreInput || nombreInput.value.trim().length < 4) {
                erroresU.push("El nombre del operador debe contener al menos 4 caracteres.");
                nombreInput?.classList.add('is-invalid');
            } else {
                nombreInput?.classList.remove('is-invalid');
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailInput || !emailRegex.test(emailInput.value.trim())) {
                erroresU.push("Debe ingresar un correo institucional válido.");
                emailInput?.classList.add('is-invalid');
            } else {
                emailInput?.classList.remove('is-invalid');
            }

            if (!rolSelect || !rolSelect.value) {
                erroresU.push("Debe seleccionar un rol para el operador.");
                rolSelect?.classList.add('is-invalid');
            } else {
                rolSelect?.classList.remove('is-invalid');
                const rolVal = parseInt(rolSelect.value, 10);
                if (rolVal === 2 && (!centroSelect || !centroSelect.value)) {
                    erroresU.push("Debe asignar un ambulatorio de adscripción al farmaceuta.");
                    centroSelect?.classList.add('is-invalid');
                } else {
                    centroSelect?.classList.remove('is-invalid');
                }
            }

            const regexPassPolicy = (p) => {
                return p.length >= 8 && p.length <= 72 &&
                    /[A-Z]/.test(p) &&
                    /[a-z]/.test(p) &&
                    /[0-9]/.test(p) &&
                    /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(p);
            };

            if (!esEdicion) {
                if (!passInput || !regexPassPolicy(passInput.value)) {
                    erroresU.push("La contraseña debe tener mínimo 8 caracteres (máx 72), mayúscula, minúscula, número y símbolo especial.");
                    passInput?.classList.add('is-invalid');
                } else {
                    passInput?.classList.remove('is-invalid');
                }
            } else {
                if (passInput && passInput.value.length > 0 && !regexPassPolicy(passInput.value)) {
                    erroresU.push("Si actualiza la contraseña, debe cumplir la política: 8+ caracteres, mayúscula, minúscula, número y símbolo especial.");
                    passInput.classList.add('is-invalid');
                } else {
                    passInput?.classList.remove('is-invalid');
                }
            }

            if (erroresU.length > 0) {
                e.preventDefault();
                const contenedor = document.getElementById('alerta-js');
                if (contenedor) {
                    contenedor.innerHTML = `
                        <div class="alert alert-danger alert-dismissible fade show shadow-sm py-2" role="alert">
                            <strong>Validación de Operador (JS):</strong>
                            <ul class="mb-0 mt-1 ps-3">
                                ${erroresU.map(err => `<li>${err}</li>`).join('')}
                            </ul>
                            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                        </div>
                    `;
                    contenedor.classList.remove('d-none');
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }
});
