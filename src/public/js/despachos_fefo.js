/**
 * SICM - Modal Asistencial de Dispensación FEFO y Cálculo Dinámico
 */
document.addEventListener('DOMContentLoaded', () => {
    const btnDispensarList = document.querySelectorAll('.btn-abrir-dispensar');
    btnDispensarList.forEach(btn => {
        btn.addEventListener('click', () => {
            try {
                const recipeData = JSON.parse(btn.getAttribute('data-recipe'));
                const pacienteData = JSON.parse(btn.getAttribute('data-paciente'));

                document.getElementById('disp_tratamiento_id').value = recipeData.id;
                document.getElementById('disp-txt-paciente').textContent = pacienteData.nombre_completo;
                document.getElementById('disp-txt-cedula').textContent = (pacienteData.tipo_doc_codigo || 'V') + '-' + pacienteData.cedula;
                document.getElementById('disp-txt-medicamento').textContent = recipeData.medicamento_nombre + ' ' + (recipeData.medicamento_concentracion || '');
                document.getElementById('disp-txt-forma').textContent = recipeData.forma_farmaceutica || '';
                document.getElementById('disp-txt-patologia').textContent = '[' + (recipeData.patologia_cie10 || '') + '] ' + (recipeData.patologia_nombre || '');
                document.getElementById('disp-txt-dosis').textContent = recipeData.dosis_mensual;

                const selectLote = document.getElementById('disp_lote_id');
                selectLote.innerHTML = '';

                const lotes = recipeData.lotes_fefo || [];
                lotes.forEach((lote, idx) => {
                    const opt = document.createElement('option');
                    opt.value = lote.id;
                    opt.setAttribute('data-stock', lote.cantidad_actual);
                    const tagFefo = (idx === 0) ? ' ★ [FEFO PRIORITARIO]' : '';
                    opt.textContent = 'Lote: ' + lote.numero_lote + ' | Vence: ' + lote.fecha_vencimiento + ' (en ' + lote.dias_para_vencer + ' d.) | Stock: ' + lote.cantidad_actual + ' unid.' + tagFefo;
                    selectLote.appendChild(opt);
                });

                const inputCant = document.getElementById('disp_cantidad');
                const txtMax = document.getElementById('disp-txt-max-dosis');

                function actualizarLimites() {
                    const optSel = selectLote.options[selectLote.selectedIndex];
                    const stockLote = optSel ? parseInt(optSel.getAttribute('data-stock'), 10) : 0;
                    const dosis = parseInt(recipeData.dosis_mensual, 10) || 1;
                    const maxVal = Math.max(1, Math.min(stockLote, dosis));
                    inputCant.max = maxVal;
                    inputCant.value = maxVal;
                    if (txtMax) txtMax.textContent = maxVal;

                    const alertCol = document.getElementById('disp-alerta-parcial-col');
                    const alertTxt = document.getElementById('disp-txt-alerta-parcial');
                    if (alertCol && alertTxt) {
                        if (stockLote < dosis) {
                            alertTxt.textContent = ` El stock del lote seleccionado (${stockLote} unid.) es inferior a la dosis autorizada (${dosis} unid./mes). La entrega parcial consumirá el cupo mensual del paciente en la red municipal.`;
                            alertCol.style.display = 'block';
                        } else {
                            alertCol.style.display = 'none';
                        }
                    }
                }

                selectLote.onchange = actualizarLimites;
                actualizarLimites();

                const modalEl = document.getElementById('modalDispensar');
                if (modalEl) {
                    const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
                    modalInstance.show();
                }
            } catch (err) {
                console.error('Error al inicializar modal de dispensación:', err);
            }
        });
    });
});
