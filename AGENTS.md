# Directivas y Reglas Obligatorias de Ingeniería (SICM)

Consulte [GEMINI.md](file:///c:/Users/mikae/OneDrive/Documentos/GitHub/sistema-distribucion/GEMINI.md) para el documento normativo completo.

## Resumen Ejecutivo de Obligaciones:
1. **Commits Obligatorios y Atómicos:** Nunca cerrar una tarea sin hacer `git commit -m "tipo(alcance): descripción"`.
2. **Prohibición Total de Comandos Destructivos:** Prohibido `git checkout <archivo>`, `git restore .`, `git reset --hard`, `git clean -fd`, `docker compose down -v`.
3. **Uso de Subagente `self`:** Obligatorio para tareas de código; permite que el usuario converse con el agente padre en paralelo sin que se detenga el flujo.
4. **Verificación de Sintaxis:** Validar siempre con `wsl docker exec salud_php_apache php -l /var/www/html/...` antes de finalizar.
5. **Arquitectura 3FN:** Crecer verticalmente en registros, nunca en columnas; cero listas en JSON; cero jerga técnica o emojis en la UI.
