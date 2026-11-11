/**
 * SICM - Utilidad global para marcar/desmarcar todos los checkboxes de una matriz RBAC
 */
function toggleCheckboxes(formId, checkState) {
    const form = document.getElementById(formId);
    if (!form) return;
    const cbs = form.querySelectorAll('input[type="checkbox"][name="permisos[]"]');
    cbs.forEach(cb => { cb.checked = checkState; });
}
