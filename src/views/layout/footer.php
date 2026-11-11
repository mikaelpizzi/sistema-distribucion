<?php
// src/views/layout/footer.php
// SICM | Cierre del Layout y Scripts Globales
declare(strict_types=1);
?>
            </main>
        </div>
    </div>

    <!-- Scripts Globales de la Plataforma -->
    <script src="public/js/validaciones.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const btnToggleSidebar = document.getElementById('btn-toggle-sidebar');
            const iconToggleSidebar = document.getElementById('icon-toggle-sidebar');
            const layoutEl = document.getElementById('sicm-layout');
            const sidebarEl = document.getElementById('sidebarMenu');

            function actualizarIconoSidebar(colapsado) {
                if (!iconToggleSidebar) return;
                if (colapsado) {
                    iconToggleSidebar.classList.remove('bi-list');
                    iconToggleSidebar.classList.add('bi-layout-sidebar-inset');
                } else {
                    iconToggleSidebar.classList.remove('bi-layout-sidebar-inset');
                    iconToggleSidebar.classList.add('bi-list');
                }
            }

            try {
                if (localStorage.getItem('sicm_sidebar_collapsed') === '1' && window.innerWidth >= 992) {
                    if (layoutEl) layoutEl.classList.add('sidebar-collapsed');
                    actualizarIconoSidebar(true);
                }
            } catch (e) { }

            if (btnToggleSidebar) {
                btnToggleSidebar.addEventListener('click', () => {
                    if (window.innerWidth >= 992) {
                        if (!layoutEl) return;
                        const colapsado = layoutEl.classList.toggle('sidebar-collapsed');
                        try {
                            localStorage.setItem('sicm_sidebar_collapsed', colapsado ? '1' : '0');
                        } catch (e) { }
                        actualizarIconoSidebar(colapsado);
                    } else {
                        if (!sidebarEl) return;
                        const bsOffcanvas = bootstrap.Offcanvas.getOrCreateInstance(sidebarEl);
                        bsOffcanvas.toggle();
                    }
                });
            }
        });
    </script>
</body>

</html>
