# Directivas y Reglas Obligatorias de Ingeniería (SICM)

Este documento contiene las reglas de operación técnica, de control de versiones y de ejecución asistida para cualquier agente o desarrollador que trabaje en el repositorio **Sistema Integrado de Control de Medicamentos (SICM)**.

---

## 1. Política Estricta de Control de Versiones (Git)

### A. Commits Obligatorios, Frecuentes y Atómicos
* **Nunca dejar trabajo sin commitear:** Al completar cualquier tarea, hito funcional o corrección de bugs, es **obligatorio** realizar un commit antes de finalizar el turno.
* **Commits Semánticos:** Utilizar la convención estándar:
  - `feat(módulo): nueva funcionalidad o vista`
  - `fix(módulo): corrección de error o validación`
  - `refactor(módulo): optimización o reestructuración sin cambio funcional`
  - `docs(módulo): actualización de documentación o esquemas`
* **Comando Estándar:**
  ```bash
  git add src/ docker/ scripts/
  git commit -m "tipo(alcance): descripción precisa y clara de lo realizado"
  ```

### B. Prohibición Absoluta de Comandos Destructivos
* **TERMINANTEMENTE PROHIBIDO:**
  - `git checkout <archivo>` (NUNCA descartar cambios del árbol de trabajo con checkout).
  - `git restore .` o `git restore <archivo>`.
  - `git reset --hard`.
  - `git clean -fd`.
  - `docker compose down -v` (destruye los volúmenes con los datos de MariaDB).
* Si una edición genera un error o conflicto, debe resolverse de forma quirúrgica sobre el archivo o utilizando archivos temporales de respaldo (`.bak`), **jamás revirtiendo a ciegas con Git**.

---

## 2. Protocolo de Trabajo Concurrente con Subagentes (`self`)

* **Uso Obligatorio de `self` para Tareas de Desarrollo:**
  - Para cualquier tarea no trivial de programación, migración, refactorización o auditoría, el agente principal **debe invocar a un subagente de tipo `self`** (`invoke_subagent`).
  - **Motivo de Experiencia de Usuario (UX):** Delegar la ejecución a un subagente `self` permite que el proceso corra de forma autónoma en segundo plano, otorgando al usuario la capacidad de **seguir conversando, haciendo consultas o alineando decisiones con el agente padre** sin que la interfaz se congele ni se bloquee el flujo de trabajo.
* **Ciclo del Subagente `self`:**
  1. Diseñar el cambio de forma modular.
  2. Aplicar las ediciones quirúrgicas.
  3. Validar la sintaxis con el linter de PHP en Docker (`wsl docker exec salud_php_apache php -l /var/www/html/...`).
  4. Ejecutar el `git commit` antes de reportar la finalización.
  5. Enviar mensaje de resumen claro al agente padre.

---

## 3. Normas de Arquitectura y Base de Datos (3FN Estricta)

* **Crecimiento Vertical, Nunca Horizontal:**
  - Las tablas deben crecer en filas (registros), nunca agregando columnas redundantes o listas quemadas (`telefono_1`, `telefono_2`, `direccion_2`).
  - Cada entidad repetible (vías de contacto, lotes, prescripciones) requiere su tabla relacional normalizada (`paciente_contactos`).
* **Uso Restringido de JSON:**
  - Solo permitido para metadatos o bitácora de auditoría inmutable (`logs_auditoria.detalles_json`).
  - **Prohibido usar JSON para guardar listas de negocio** (viola la 1FN y las exigencias de la cátedra de Base de Datos).
* **Cero Jerga Técnica y Emojis en la UI Clínica ("No AI-Slop"):**
  - La interfaz institucional debe ser sobria, formal y médica.
  - No usar términos académicos en formularios (usar «Vías de Contacto», nunca «Normalización 1FN»).
  - No usar emojis en encabezados de tarjetas ni en membretes institucionales.

---

## 4. Verificación Obligatoria de Entorno

* Antes de dar por buena cualquier modificación:
  1. **Linter PHP:** `wsl docker exec salud_php_apache php -l /var/www/html/archivo.php` (debe retornar `No syntax errors detected`).
  2. **Contenedores Activos:** `salud_php_apache` (puerto 8080) y `salud_maria_db` (puerto 3306).
  3. **Control de Errores PDO:** Todo fallo de integridad (`1062`, `1451`, `1452`) debe mapearse amigablemente y registrarse en la bitácora de auditoría forense (`logs_auditoria`).
