# Sistema Integrado de Control de Medicamentos

> **Red de Salud Pública Municipal | Proyecto de Bases de Datos**  
> **Estudiante:** Mikael Pizzi (C.I. 27.777.243)  
> **Base de Datos:** `control_medicamentos_red`  
> **Motor RDBMS:** MariaDB 11.4 / MySQL 8.0 (Motor de Almacenamiento: InnoDB)  
> **Stack de Desarrollo:** PHP 8.3 (Nativo / PDO) + Vanilla JavaScript (ES6+) + CSS3 / Bootstrap 5 + Docker  

---

## 1. Descripción del Proyecto

El **Sistema Integrado de Control de Medicamentos** es una solución de ingeniería de datos y salud pública concebida para regular, auditar y fiscalizar la distribución de medicamentos para patologías crónicas en la red de ambulatorios municipales.

El sistema fue diseñado respondiendo a tres problemáticas críticas detectadas en la gestión sanitaria municipal:
1. **Control de merma por caducidad (Estrategia FEFO):** Despacho obligatorio del lote con fecha de caducidad más cercana (*First Expired, First Out*) para evitar pérdidas de insumos en los depósitos de los ambulatorios.
2. **Bloqueo transaccional contra doble retiro mensual:** Restricción a nivel de motor de base de datos que impide de forma estricta que un paciente retire dos veces su tratamiento en el mismo mes calendario en centros de salud diferentes.
3. **Trazabilidad sanitaria sin borrado destructivo:** Prohibición absoluta de eliminación física (*Hard Delete*), implementando borrado lógico (*Soft Delete*) en entidades operativas y bitácora forense estrictamente inmutable (*WORM*) para auditoría de contraloría sanitaria.

---

## 2. Implementación del Esquema de Datos y Correspondencia con la Propuesta

El esquema DDL implementado en [`docker/mysql/01_schema.sql`](file:///c:/Users/mikae/OneDrive/Documentos/GitHub/sistema-distribucion/docker/mysql/01_schema.sql) sigue con exactitud la propuesta arquitectónica original, cumpliendo con la Tercera Forma Normal (3FN), integridad referencial y las políticas forenses definidas para el proyecto.

```
┌────────────────────────────────────────────────────────────────────────────┐
│ 1. CATÁLOGOS MAESTROS 3FN                                                  │
│ roles ── tipos_documento ── formas_farmaceuticas ── tipos_centro_salud    │
└──────────────┬─────────────────────────────────────────────┬───────────────┘
               │                                             │
               ▼                                             ▼
┌──────────────────────────────┐              ┌──────────────────────────────┐
│ 2. SEGURIDAD Y OPERADORES    │              │ 3. RED DE AMBULATORIOS       │
│ usuarios                     │              │ centros_salud ◄─► recursos   │
│ (RBAC / Jurisdicción)        │              │ (Relación N:M centro_recursos│
└──────────────┬───────────────┘              └──────────────┬───────────────┘
               │                                             │
               │         ┌───────────────────────────────────┤
               │         ▼                                   │
┌──────────────┴────────────────────────┐                    │
│ 4. CATÁLOGO FARMACÉUTICO Y ALMACÉN    │                    │
│ medicamentos ◄─── lotes_inventario ───┼────────────────────┘
│                   (Índice FEFO B+)    │
└────────────────────────┬──────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────────────────────────┐
│ 5. DISPENSACIÓN Y CANDADO TRANSACCIONAL                                    │
│ pacientes ──► tratamientos_autorizados ──► despachos ──► despacho_detalles │
│ (Padrón Único)  (Cuota Mensual Récipe)      (UNIQUE KEY)  (Desglose 1FN)   │
└────────────────────────────────────────────────────────────────────────────┘
```

### 2.1. Módulo de Catálogos Maestros y Parametrización (3FN)
* **Tablas:** `roles`, `tipos_documento`, `formas_farmaceuticas`, `tipos_centro_salud`, `recursos_operativos`.
* **Criterio adoptado:** Se normalizó la clasificación asistencial y los tipos de documentos en tablas independientes.
* **Alternativa descartada:** Uso de columnas estáticas tipo `ENUM('admin', 'farmaceuta')` o `ENUM('Ambulatorio I', 'Ambulatorio II')`.
* **Justificación técnica:** Los `ENUM` queman la estructura relacional. Incorporar una nueva clasificación o rol en un entorno en producción exigiría ejecutar un `ALTER TABLE`, bloqueando la tabla físicamente (*Metadata Lock*). La normalización en 3FN permite incorporar clasificaciones en caliente mediante simples sentencias `INSERT`.

### 2.2. Módulo de Seguridad, Operadores y Acceso RBAC
* **Tabla:** `usuarios`.
* **Columnas clave:** `id` (PK), `email` (UNIQUE), `password_hash`, `rol_id` (FK), `centro_salud_id` (FK, NULLable), `activo` (TINYINT).
* **Criterio adoptado:** Cada despacho y lote almacena el identificador del operador responsable (`usuario_id`). La columna `centro_salud_id` actúa como delimitador de jurisdicción: es `NULL` para el Administrador Central (acceso global a la red) y toma un valor entero no nulo para el Farmaceuta, restringiendo sus consultas estrictamente a su ambulatorio de adscripción.
* **Política de borrado:** Borrado lógico (*Soft Delete*) mediante `activo = 0` para preservar la auditoría histórica de los despachos despachados por dicho operador.

### 2.3. Módulo de Red de Ambulatorios y Relación N:M
* **Tablas:** `centros_salud`, `centro_recursos`, `recursos_operativos`.
* **Criterio adoptado:** Separación de las capacidades e instalaciones técnicas (cadena de frío, generador eléctrico, farmacia 24h, ambulancia) a través de la tabla puente `centro_recursos` con clave primaria compuesta `(centro_salud_id, recurso_id)`.
* **Justificación técnica:** Cumplimiento de la 1FN al evitar columnas multivaluadas o banderas booleanas agregadas horizontalmente en la tabla principal del centro de salud.

### 2.4. Módulo de Almacén y Control de Mermas (Estrategia FEFO)
* **Tablas:** `medicamentos` y `lotes_inventario`.
* **Columnas clave:** `centro_salud_id`, `medicamento_id`, `numero_lote`, `fecha_vencimiento`, `cantidad_actual` con restricción `CHECK (cantidad_actual >= 0)`.
* **Índice B+ Tree compuesto:**
  ```sql
  INDEX idx_inventario_fefo (centro_salud_id, medicamento_id, fecha_vencimiento ASC);
  ```
* **Criterio adoptado:** Existencias descentralizadas por centro y segregadas por lote físico. El índice compuesto permite resolver la consulta del lote con caducidad más cercana en tiempo logarítmico $\mathcal{O}(\log N)$, cumpliendo la regla de izquierda a derecha sin escaneo secuencial (*Full Table Scan*).
* **Alternativa descartada:** Manejar una columna acumulativa `stock_total INT` en la tabla de medicamentos.
* **Justificación técnica:** Un número acumulado destruye la trazabilidad por lote, imposibilitando identificar cuáles frascos vencen primero y generando pérdidas millonarias por caducidad en anaqueles.

### 2.5. Módulo de Récipes y Delimitación de Alcance
* **Tablas:** `pacientes` y `tratamientos_autorizados`.
* **Criterio adoptado:** Conecta al paciente con su fármaco prescrito y fija una cuota mensual estricta (`dosis_mensual INT NOT NULL CHECK (dosis_mensual > 0)`), además de la vigencia del récipe físico (`fecha_vencimiento_recipe DATE`).
* **Delimitación:** El sistema acota su alcance a la transcripción del récipe presentado para el suministro equitativo de recursos. Se descartó modelar historias clínicas completas o consultas médicas complejas para evitar sobredimensionar el proyecto y desviar el objetivo de control de suministros.

### 2.6. Módulo de Entrega y Bloqueo de Doble Retiro (1FN)
* **Tablas:** `despachos` (Cabecera) y `despacho_detalles` (Desglose de Lotes).
* **Restricción de integridad clave:**
  ```sql
  UNIQUE KEY uk_retiro_mensual (tratamiento_id, periodo_mes_anio);
  ```
* **Criterio adoptado:**
  1. **Bloqueo a nivel de base de datos:** La clave única compuesta `(tratamiento_id, periodo_mes_anio)` impide físicamente que un paciente retire dos veces en el mismo período mensual (ej. `'2026-11'`), incluso si acude a ambulatorios distintos de la red.
  2. **Cumplimiento de 1FN:** Una entrega puede requerir unidades de dos lotes distintos para completar la dosis mensual autorizada (ej. 10 tabletas de un lote por caducar y 20 de un lote nuevo). La división en cabecera y detalle garantiza atomicidad sin listas concatenadas.
* **Alternativa descartada:** Validar el doble retiro únicamente mediante código backend (PHP).
* **Justificación técnica:** La validación en software es susceptible a condiciones de carrera (*Race Conditions*): si dos ambulatorios registran peticiones simultáneas, ambas superarían la verificación de software antes de guardar. Al delegar la restricción al motor RDBMS, la consistencia transaccional ACID es absoluta.

### 2.7. Módulo de Auditoría Forense Inmutable (WORM)
* **Tabla:** `logs_auditoria`.
* **Triggers de seguridad a nivel de motor:**
  ```sql
  DELIMITER $$
  CREATE TRIGGER trg_bloquear_update_logs
  BEFORE UPDATE ON logs_auditoria FOR EACH ROW
  BEGIN
      SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_auditoria es estrictamente inmutable (WORM). No se permiten modificaciones.';
  END$$

  CREATE TRIGGER trg_bloquear_delete_logs
  BEFORE DELETE ON logs_auditoria FOR EACH ROW
  BEGIN
      SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Integridad Forense Violada: La tabla logs_auditoria es estrictamente inmutable (WORM). No se permiten eliminaciones físicas.';
  END$$
  DELIMITER ;
  ```
* **Criterio adoptado:** Modelo *Write Once, Read Many*. Cada mutación en la base de datos almacena el operador, dirección IP, agente de usuario y un delta estructurado en formato JSON con los valores anteriores y posteriores. Ningún usuario ni proceso tiene privilegios para alterar o purgar esta bitácora.

### 2.8. Vistas SQL como Capa de Abstracción
* **Vistas implementadas:** `vw_centros_salud` y `vw_logs_auditoria`.
* **Criterio adoptado:** El backend consulta directamente estas tablas virtuales, desacoplando la lógica de negocio de los `JOIN` relacionales subyacentes.

---

## 3. Estructura del Repositorio

```text
sistema-distribucion/
├── docker/
│   ├── mysql/
│   │   ├── 01_schema.sql             # Esquema DDL en 3FN, índices B+, vistas y triggers WORM
│   │   └── 02_seeders.sql            # Datos maestros canónicos y cuentas operativas iniciales
│   └── php/
│       └── Dockerfile                # Imagen PHP 8.3 Apache con PDO y cliente MariaDB
├── docker-compose.yml                # Orquestación de contenedores y red interna bridge
├── respaldos/                        # Directorio local de copias de seguridad (.sql.gz) [GitIgnored]
├── scripts/
│   ├── daemon_respaldo.sh            # Demonio automatizado de respaldo para entornos Unix / WSL
│   ├── daemon_respaldo.ps1           # Demonio automatizado de respaldo para Windows PowerShell
│   ├── restaurar.sh                  # Restauración certificada con validación criptográfica SHA-256
│   ├── reset_quickstart.php          # Motor PHP para restablecimiento canónico de base de datos
│   └── poblar_medicamentos.php       # Generador de carga masiva para pruebas de rendimiento
├── src/
│   ├── config/
│   │   └── database.php              # Conexión nativa PDO con control de excepciones y sin emulación
│   ├── helpers/
│   │   ├── auth.php                  # Autenticación, sesiones y control de acceso por roles (RBAC)
│   │   └── auditoria.php             # Funciones de trazabilidad inmutable y resolución de deltas
│   ├── public/
│   │   ├── css/
│   │   │   └── app.css               # Hoja de estilos de interfaz asistencial responsiva
│   │   └── js/
│   │       └── validaciones.js       # Validación preventiva y sanitización en cliente (Vanilla JS)
│   ├── scripts/
│   │   ├── reset_quickstart.php      # Proxy interno para restablecimiento desde contenedor
│   │   └── poblar_medicamentos.php   # Proxy interno para generación de carga desde contenedor
│   ├── index.php                     # Panel principal unificado (Ambulatorios, Catálogos, Operadores y Auditoría)
│   ├── login.php                     # Control de acceso con formulario sobrio de autenticación
│   ├── logout.php                    # Cierre de sesión seguro con registro en bitácora
│   ├── setup.php                     # Asistente de primer arranque y configuración inicial del sistema
│   └── procesar.php                  # Controlador transaccional centralizado para operaciones CRUD
├── .gitattributes                    # Normalización de saltos de línea (LF en scripts Unix)
├── .gitignore                        # Exclusión de temporales, credenciales y volcados pesados
├── reset_quickstart.bat              # Acceso rápido en Windows para reinicio de demostración
├── reset_quickstart.ps1              # Script PowerShell para reinicio de demostración
└── README.md                         # Documentación técnica integral del proyecto
```

---

## 4. Despliegue con Docker

### Requisitos Previos
* Docker Engine 24+ y Docker Compose v2+ instalados y operativos.

### Procedimiento de Despliegue
1. **Construir e iniciar los servicios:**
   ```bash
   docker compose up -d --build
   ```

2. **Parámetros de acceso:**
   * **Aplicación Web:** `http://localhost:8080`
   * **Servicio de Base de Datos (Puerto 3306):**
     * Host: `localhost` (o `db` internamente en Docker)
     * Base de datos: `control_medicamentos_red`
     * Usuario: `app_salud_user`
     * Contraseña: `app_password_seguro_2026`

3. **Detener el entorno:**
   ```bash
   docker compose down
   ```

---

## 5. Inicialización del Sistema y Perfiles de Acceso

### Asistente de Configuración Inicial
Diseñado para el primer arranque del sistema cuando la base de datos se encuentra limpia:
1. Abra `http://localhost:8080/` en el navegador.
2. Al detectar que no existen operadores registrados, el software redirige de forma automática al asistente `setup.php`.
3. Ingrese los datos del Administrador Central Municipal:
   * **Nombre:** Entre 4 y 80 caracteres.
   * **Correo institucional:** Formato de correo válido.
   * **Contraseña:** Mínimo 8 caracteres, con mayúscula, minúscula, número y símbolo especial. Incluye indicador dinámico de complejidad en tiempo real.
4. **Casilla de datos de red asistencial:**
   * Si permanece marcada, aprovisiona de forma inmediata 3 ambulatorios en Mérida y un operador farmacéutico auxiliar para evaluar el sistema.
5. Al enviar el formulario, el sistema crea la cuenta administrativa raíz y **auto-bloquea permanentemente el asistente**, redirigiendo al panel de control.

### Credenciales de Demostración
Si el sistema fue inicializado con los datos de prueba (o mediante `02_seeders.sql`), dispone de los siguientes perfiles:

| Perfil / Rol | Correo Institucional | Contraseña | Alcance y Jurisdicción (RBAC) |
| :--- | :--- | :--- | :--- |
| **Administrador Central** | `admin@salud.prueba.ve` | `admin123` | **Acceso Global:** Gestión de ambulatorios, catálogos 3FN, operadores de seguridad, auditoría inmutable y descarga de respaldos. |
| **Farmaceuta Operador** | `farmacia.llano@salud.prueba.ve` | `farmacia123` | **Acceso Restringido:** Delimitado estrictamente a su ambulatorio de adscripción. Bloqueo total de catálogos, operadores, auditoría y respaldos. |

---

## 6. Guía de Ejecución de Scripts Operativos

Todos los scripts de mantenimiento y administración se encuentran centralizados en el directorio `scripts/`:

### 6.1. Demonio Automatizado de Respaldo Transaccional
Ejecuta un volcado en caliente sin bloquear operaciones en los ambulatorios (`--single-transaction`), comprime el archivo en formato gzip nivel 9, genera una firma criptográfica SHA-256 para validación forense y purga automáticamente copias con más de 7 días de antigüedad.

* **En entornos Linux / macOS / WSL:**
  ```bash
  bash scripts/daemon_respaldo.sh
  ```
* **En entornos Windows PowerShell:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File scripts/daemon_respaldo.ps1
  ```

### 6.2. Restauración Certificada de Base de Datos
Restaura un volcado verificando primero que el hash SHA-256 coincida con el archivo de firma. Si el archivo fue alterado o corrompido, la restauración se aborta físicamente por seguridad:
```bash
bash scripts/restaurar.sh respaldos/respaldo_sicm_YYYYMMDD_HHMMSS.sql.gz
```

### 6.3. Restablecimiento para Demostración en Vivo
Vacia la base de datos a cero usuarios, limpia tablas transaccionales, restaura los catálogos maestros canónicos y reactiva los triggers WORM. Prepara el entorno para demostrar el asistente de primer arranque:

* **En Windows (Acceso directo por archivo por lotes):**
  ```cmd
  reset_quickstart.bat
  ```
* **En Windows PowerShell:**
  ```powershell
  powershell -ExecutionPolicy Bypass -File reset_quickstart.ps1
  ```
* **Desde el contenedor Docker en Linux / WSL:**
  ```bash
  docker exec salud_php_apache php /var/www/html/scripts/reset_quickstart.php
  ```

### 6.4. Generación de Carga Masiva (Pruebas de Rendimiento en InnoDB)
Inserta medicamentos en bloques transaccionales atómicos de 500 registros para evaluar la velocidad de inserción y la densidad de almacenamiento en las páginas de 16 KB de MySQL:

* **Poblar 1.000 registros de medicamentos:**
  ```bash
  docker exec salud_php_apache php /var/www/html/scripts/poblar_medicamentos.php 1000
  ```
* **Limpiar los medicamentos de prueba y restablecer el autoincremento:**
  ```bash
  docker exec salud_php_apache php /var/www/html/scripts/poblar_medicamentos.php --limpiar
  ```

---

## 7. Arquitectura de Respaldos y Resguardo Continuo

El sistema implementa una **estrategia de defensa en profundidad en 3 niveles**, cumpliendo con la **Regla 3-2-1** (3 copias de los datos, 2 soportes diferentes, 1 copia externa):

1. **Nivel de Motor de Base de Datos (Snapshot MVCC):**  
   Uso de `mariadb-dump` con snapshot transaccional repetible (`--single-transaction --quick --hex-blob`), garantizando consistencia relacional sin bloquear las tablas físicas y permitiendo que la dispensación continúe sin interrupciones.
2. **Nivel de Sistema Operativo (Scripting Automatizado):**  
   Escritura atómica en archivo temporal, compresión y firma criptográfica SHA-256 complementaria. Política de retención de 7 días para evitar saturación de almacenamiento en el servidor municipal.
3. **Nivel de Aplicación Web (Streaming en Memoria Constante $\mathcal{O}(1)$):**  
   Al pulsar el botón **Crear Respaldo** en el panel de control, PHP no carga el archivo en memoria RAM. Transmite el volcado comprimido directamente hacia la conexión HTTP mediante subproceso `proc_open()` y tubería de salida `fpassthru()`, operando con un búfer de menos de 2 MB de memoria independientemente del volumen de datos, y registrando la exportación en la bitácora inmutable.

---

## 8. Licencia

Sistema desarrollado para el control y trazabilidad de insumos sanitarios en la red de salud pública municipal. Código abierto bajo licencia MIT.
