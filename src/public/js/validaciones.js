// src/public/js/validaciones.js
// Validación y sanitización en cliente (Vanilla JS / ES6+)
// Validación preventiva en navegador para mitigar latencia y rechazar inconsistencias antes del procesamiento en backend.
// Reglas: Cero dependencias externas, sanitización en tiempo real y tipado estricto.

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

    // =========================================================================
    // 4. VALIDACIÓN PREVENTIVA DE FORMULARIO DE PACIENTES Y VÍAS DE CONTACTO (1FN)
    // =========================================================================
    const formPaciente = document.getElementById('form-paciente');
    if (formPaciente) {
        formPaciente.addEventListener('submit', (e) => {
            const erroresP = [];

            const cedula = document.getElementById('paciente_cedula');
            const nombre = document.getElementById('paciente_nombre');
            const tipoDoc = document.getElementById('paciente_tipo_doc');
            const fechaNac = document.getElementById('paciente_fecha_nac');

            if (!tipoDoc || !tipoDoc.value || parseInt(tipoDoc.value, 10) <= 0) {
                erroresP.push("Debe seleccionar un tipo de documento de identidad válido.");
                tipoDoc?.classList.add('is-invalid');
            } else {
                tipoDoc?.classList.remove('is-invalid');
            }

            const cedulaVal = cedula ? cedula.value.trim() : '';
            if (!/^[0-9]{5,12}$/.test(cedulaVal)) {
                erroresP.push("La cédula o documento debe contener únicamente entre 5 y 12 dígitos numéricos.");
                cedula?.classList.add('is-invalid');
            } else {
                cedula?.classList.remove('is-invalid');
            }

            const nombreVal = nombre ? nombre.value.trim() : '';
            if (nombreVal.length < 3) {
                erroresP.push("El nombre completo del paciente debe contener al menos 3 caracteres.");
                nombre?.classList.add('is-invalid');
            } else {
                nombre?.classList.remove('is-invalid');
            }

            if (fechaNac && fechaNac.value) {
                const hoyStr = new Date().toISOString().split('T')[0];
                if (fechaNac.value > hoyStr) {
                    erroresP.push("La fecha de nacimiento no puede ser una fecha futura.");
                    fechaNac.classList.add('is-invalid');
                } else {
                    fechaNac.classList.remove('is-invalid');
                }
            }

            // Validación de vías de contacto (1FN)
            const filasContacto = formPaciente.querySelectorAll('.fila-contacto');
            filasContacto.forEach((fila, idx) => {
                const select = fila.querySelector('select[name="contactos_tipo[]"]');
                const inputVal = fila.querySelector('input[name="contactos_valor[]"]');
                if (!inputVal) return;

                const val = inputVal.value.trim();
                if (val === '') {
                    inputVal.classList.remove('is-invalid');
                    return;
                }

                const opt = select ? select.options[select.selectedIndex] : null;
                const codigo = opt ? (opt.dataset.codigo || opt.getAttribute('data-codigo') || '').toUpperCase() : '';
                const texto = opt ? opt.textContent.toUpperCase() : '';
                const esEmail = (select && String(select.value) === '4') || codigo === 'EMAIL_ALTERNO' || codigo.includes('EMAIL') || texto.includes('CORREO');

                if (esEmail) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(val)) {
                        erroresP.push(`El correo electrónico '${val}' (vía de contacto #${idx + 1}) tiene un formato inválido.`);
                        inputVal.classList.add('is-invalid');
                    } else {
                        inputVal.classList.remove('is-invalid');
                    }
                } else {
                    const digits = val.replace(/\D/g, '');
                    if (digits.length < 10 || digits.length > 11) {
                        erroresP.push(`El número telefónico '${val}' (vía de contacto #${idx + 1}) debe contener entre 10 y 11 dígitos numéricos.`);
                        inputVal.classList.add('is-invalid');
                    } else {
                        inputVal.classList.remove('is-invalid');
                    }
                }
            });

            if (erroresP.length > 0) {
                e.preventDefault();
                let contenedor = document.getElementById('alerta-paciente-js');
                if (!contenedor) {
                    contenedor = document.createElement('div');
                    contenedor.id = 'alerta-paciente-js';
                    const modalBody = formPaciente.querySelector('.modal-body');
                    if (modalBody) {
                        modalBody.prepend(contenedor);
                    }
                }
                contenedor.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show shadow-sm py-2 mb-2" role="alert">
                        <strong>Validación de Paciente (JS):</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            ${erroresP.map(err => `<li>${err}</li>`).join('')}
                        </ul>
                        <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                    </div>
                `;
                contenedor.classList.remove('d-none');
                contenedor.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                const contenedor = document.getElementById('alerta-paciente-js');
                if (contenedor) {
                    contenedor.classList.add('d-none');
                }
            }
        });
    }

    // =========================================================================
    // 5. VALIDACIÓN Y COHERENCIA DE FECHAS EN RÉCIPES MÉDICOS
    // =========================================================================
    const emisionInput     = document.getElementById('recipe_fecha_emision');
    const vencimientoInput = document.getElementById('recipe_fecha_vencimiento');
    if (emisionInput && vencimientoInput) {
        const syncFechasRecipe = () => {
            if (emisionInput.value) {
                vencimientoInput.min = emisionInput.value;
                if (vencimientoInput.value && vencimientoInput.value < emisionInput.value) {
                    vencimientoInput.value = emisionInput.value;
                }
            }
        };
        emisionInput.addEventListener('change', syncFechasRecipe);
        emisionInput.addEventListener('input', syncFechasRecipe);
        syncFechasRecipe();
    }

    const formRecipe = document.getElementById('form-recipe');
    if (formRecipe) {
        formRecipe.addEventListener('submit', (e) => {
            const pacSelect   = document.getElementById('recipe_paciente_id');
            const medSelect   = document.getElementById('recipe_medicamento_id');
            const patSelect   = document.getElementById('recipe_patologia_id');
            const dosisInput  = document.getElementById('recipe_dosis_mensual');
            const emision     = document.getElementById('recipe_fecha_emision');
            const vencimiento = document.getElementById('recipe_fecha_vencimiento');
            const medico      = document.getElementById('recipe_medico_id') || document.getElementById('recipe_medico_prescriptor');

            const erroresR = [];

            if (!pacSelect || !pacSelect.value || parseInt(pacSelect.value, 10) <= 0) {
                erroresR.push("Debe seleccionar un paciente registrado en el padrón.");
                pacSelect?.classList.add('is-invalid');
            } else {
                pacSelect?.classList.remove('is-invalid');
            }

            if (!patSelect || !patSelect.value || parseInt(patSelect.value, 10) <= 0) {
                erroresR.push("Debe seleccionar la patología CIE-10 diagnosticada.");
                patSelect?.classList.add('is-invalid');
            } else {
                patSelect?.classList.remove('is-invalid');
            }

            if (!medSelect || !medSelect.value || parseInt(medSelect.value, 10) <= 0) {
                erroresR.push("Debe seleccionar un medicamento del vademécum.");
                medSelect?.classList.add('is-invalid');
            } else {
                medSelect?.classList.remove('is-invalid');
            }

            const dosisVal = dosisInput ? parseInt(dosisInput.value, 10) : 0;
            if (!dosisInput || isNaN(dosisVal) || dosisVal <= 0) {
                erroresR.push("La dosis mensual autorizada debe ser un número entero mayor a cero.");
                dosisInput?.classList.add('is-invalid');
            } else {
                dosisInput?.classList.remove('is-invalid');
            }

            if (!emision || !emision.value) {
                erroresR.push("Debe ingresar la fecha de emisión del récipe.");
                emision?.classList.add('is-invalid');
            } else {
                emision?.classList.remove('is-invalid');
            }

            if (!vencimiento || !vencimiento.value) {
                erroresR.push("Debe ingresar la fecha de vencimiento del récipe.");
                vencimiento?.classList.add('is-invalid');
            } else if (emision && emision.value && vencimiento.value < emision.value) {
                erroresR.push("La fecha de vencimiento no puede ser anterior a la fecha de emisión.");
                vencimiento.classList.add('is-invalid');
            } else {
                vencimiento?.classList.remove('is-invalid');
            }

            if (!medico || !medico.value || (medico.tagName === 'SELECT' && parseInt(medico.value, 10) <= 0) || (medico.tagName === 'INPUT' && medico.value.trim().length < 3)) {
                erroresR.push("Debe seleccionar un médico prescriptor acreditado en el padrón oficial.");
                medico?.classList.add('is-invalid');
            } else {
                medico?.classList.remove('is-invalid');
            }

            if (erroresR.length > 0) {
                e.preventDefault();
                alert("Verifique los siguientes datos del récipe:\n• " + erroresR.join("\n• "));
            }
        });
    }
});
