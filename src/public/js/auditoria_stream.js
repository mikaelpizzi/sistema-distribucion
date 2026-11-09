/**
 * SICM - Consola de Investigación Forense y Auditoría
 * Control de Actualización Bajo Demanda y Sondeo Pasivo de Novedades
 */
document.addEventListener('DOMContentLoaded', () => {
    const btnActualizar = document.getElementById('btn-actualizar-auditoria');
    const iconActualizar = document.getElementById('icon-actualizar');
    const badgeNovedades = document.getElementById('badge-novedades');
    const tableContainer = document.getElementById('forensic-table-container');

    if (!btnActualizar || !tableContainer) return;

    const subtab = tableContainer.getAttribute('data-subtab') || 'eventos';
    const maxId = parseInt(tableContainer.getAttribute('data-max-id') || '0', 10);

    // 1. Acción al presionar el botón de Actualizar
    btnActualizar.addEventListener('click', () => {
        if (iconActualizar) {
            iconActualizar.classList.add('spin-icon');
        }
        btnActualizar.disabled = true;

        // Recarga completa y limpia preservando URL, filtros y subpestaña activa
        window.location.reload();
    });

    // 2. Detección pasiva de nuevos registros (cada 15 segundos)
    // No altera el DOM de la tabla bajo ninguna circunstancia. La tabla permanece 100% estática.
    if (maxId > 0) {
        async function consultarNovedades() {
            try {
                const url = `stream_logs.php?desde_id=${maxId}&subtab=${encodeURIComponent(subtab)}&solo_conteo=1`;
                const res = await fetch(url, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!res.ok) return;

                const data = await res.json();
                if (data && data.success && typeof data.count === 'number') {
                    if (data.count > 0 && badgeNovedades) {
                        badgeNovedades.textContent = data.count > 99 ? '99+' : String(data.count);
                        badgeNovedades.classList.remove('d-none');
                        btnActualizar.setAttribute('title', `${data.count} nuevo(s) registro(s) detectado(s). Haga clic para actualizar la vista.`);
                    }
                }
            } catch (err) {
                // Sondeo silencioso sin interrumpir la experiencia de usuario
            }
        }

        setInterval(consultarNovedades, 15000);
    }
});
