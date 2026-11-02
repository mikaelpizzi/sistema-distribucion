# Sistema Integrado de Control de Medicamentos

> **Red Municipal de Salud | Proyecto de Bases de Datos**  
> **Estudiante:** Mikael Pizzi (C.I. 27.777.243)  
> **Motor RDBMS:** MariaDB 11.4 / MySQL 8.0 (Storage Engine: InnoDB)  
> **Stack Tecnológico:** PHP 8.3 (Nativo / PDO) + Vanilla JavaScript (ES6+) + CSS3 / Bootstrap 5 + Docker  

---

## 1. Descripción del Proyecto

El **Sistema Integrado de Control de Medicamentos** es una plataforma de ingeniería de datos y salud pública diseñada para regular y auditar la dispensación de tratamientos crónicos en la red de ambulatorios municipales.

El sistema resuelve tres desafíos críticos mediante restricciones y arquitectura en el motor de base de datos:
1. **Control de merma por caducidad (Estrategia FEFO):** Despacho obligatorio del lote con fecha de vencimiento más próxima (*First Expired, First Out*).
2. **Candado transaccional contra retiros duplicados:** Bloqueo a nivel de motor RDBMS mediante clave única compuesta (`UNIQUE KEY (tratamiento_id, periodo_mes_anio)`), inmune a condiciones de carrera (*Race Conditions*) entre sedes.
3. **Trazabilidad sanitaria sin borrado destructivo:** Prohibición estricta de *Hard Delete* en entidades operativas y transaccionales, implementando *Soft Delete* (`activo = 0`) y auditoría forense inmutable (`logs_auditoria`).

---

## 2. Decisiones de Arquitectura de Datos (ADR)

* **Normalización en Tercera Forma Normal (3FN):**  
  Se eliminaron dependencias transitivas y multivaluadas mediante catálogos maestros (`tipos_centro_salud`, `recursos_operativos`, `formas_farmaceuticas`, `tipos_documento`, `roles`). Se prohíbe el uso de tipos `ENUM` para clasificaciones operativas, evitando bloqueos físicos de tabla (*Metadata Locks / ALTER TABLE*) en producción.
* **Eficiencia en Páginas de Disco (InnoDB 16 KB):**  
  Ancho de fila compacto y tipos atómicos para maximizar la densidad de tuplas por página en el *Buffer Pool* y minimizar operaciones de Entrada/Salida (I/O).
* **Vistas SQL como Capa de Abstracción:**  
  Las consultas se exponen mediante vistas desacopladas (`vw_centros_salud`, `vw_logs_auditoria`), permitiendo al backend consultar directamente tablas virtuales sin acoplarse al DDL físico.
* **Índice Compuesto FEFO (Regla de Izquierda a Derecha):**  
  Índice en árbol B+ `(centro_salud_id, medicamento_id, fecha_vencimiento ASC)` para resolver la selección del lote en tiempo logarítmico $\mathcal{O}(\log N)$, evitando escaneo secuencial (*Full Table Scan*).
* **Auditoría Forense Integral (No Repudio):**  
  Cada mutación (`CREAR`, `EDITAR`, `DESACTIVAR`, `REACTIVAR`) almacena operador responsable, IP de origen, timestamp y snapshot JSON con el delta de los campos alterados.
* **Política de Auto-Registro:**  
  **Deshabilitada por diseño.** Al tratarse de un sistema cerrado municipal para fiscalización de recursos públicos, el alta de operadores es potestad exclusiva del Administrador Central.

---

## 3. Estructura del Repositorio

```text
sistema-distribucion/
├── docker/
│   ├── mysql/
│   │   ├── 01_schema.sql             # Esquema DDL en 3FN, vistas e índices B+
│   │   └── 02_seeders.sql            # Datos maestros iniciales y usuarios de prueba
│   └── php/
│       └── Dockerfile                # Imagen PHP 8.3 Apache con extensiones PDO y mariadb-client
├── docker-compose.yml                # Orquestación de servicios en red interna
├── respaldos/                        # Almacenamiento local de copias (.sql.gz + .sha256) [GitIgnored]
├── scripts/
│   ├── daemon_respaldo.sh            # Demonio Cron automatizado (Bash/Linux/WSL)
│   ├── daemon_respaldo.ps1           # Script de respaldo automatizado para Windows PowerShell
│   ├── restaurar.sh                  # Restauración con certificación criptográfica SHA-256
│   └── poblar_medicamentos.php       # Proxy CLI para ejecuciones directas
├── src/
│   ├── config/
│   │   └── database.php              # Conexión nativa PDO con reintentos y excepciones
│   ├── helpers/
│   │   ├── auth.php                  # Guardián de autenticación, sesiones y control RBAC
│   │   └── auditoria.php             # Módulo forense para registro inmutable WORM en BD
│   ├── public/
│   │   ├── css/
│   │   │   └── app.css               # Estilos de alta densidad empresarial (0 Slop)
│   │   └── js/
│   │       └── validaciones.js       # Validaciones nativas en cliente (Vanilla JS)
│   ├── scripts/
│   │   ├── test_auth.php             # Test automatizado de hashing Bcrypt, login y RBAC
│   │   ├── test_crud.php             # Test unitario idempotente del flujo CRUD + logs
│   │   ├── test_maestras.php         # Test unitario de parametrización en 3FN
│   │   └── poblar_medicamentos.php   # Generador masivo por lotes (Batch Multi-Row)
│   ├── index.php                     # Dashboard Unificado (Ambulatorios, Catálogos, Operadores y Bitácora)
│   ├── login.php                     # Inicio de sesión sobrio con selector de seed bajo demanda
│   ├── logout.php                    # Cierre seguro de sesión con auditoría forense
│   ├── setup.php                     # Asistente de Primer Arranque (Quick Start / Génesis del Admin)
│   └── procesar.php                  # Controlador Transaccional Único Centralizado
├── .gitignore
└── README.md
```

---

## 4. Despliegue con Docker

### Requisitos
* Docker Engine 24+ y Docker Compose v2+

### Pasos de inicialización
1. **Iniciar contenedores:**
   ```bash
   docker compose up -d --build
   ```

2. **Acceso al sistema:**
   * **Aplicación Web:** `http://localhost:8080`
   * **Base de Datos (Puerto 3306):**
     * Base de datos: `control_medicamentos_red`
     * Usuario: `app_salud_user`
     * Contraseña: `app_password_seguro_2026`

3. **Detener el entorno:**
   ```bash
   docker compose down
   ```

---

## 4.1. Primer Arranque y Autenticación (Quick Start vs Modo Demo)

El sistema provee **dos métodos de inicio** diseñados tanto para instalaciones de producción limpias como para evaluaciones académicas:

### Opción A: Asistente de Primer Arranque (`setup.php` / Quick Start)
Diseñado para la inicialización institucional desde cero (cuando la base de datos no contiene operadores registrados):

1. Abre `http://localhost:8080/` en tu navegador.
2. Como el sistema detecta **0 usuarios registrados**, redirige automáticamente al asistente de inicialización: `setup.php`.
3. Introduce los datos del Administrador Central Municipal:
   * **Nombre completo:** Ej. `Mikael Pizzi` (validación en tiempo real de 4 a 80 caracteres).
   * **Correo institucional:** Ej. `admin@salud.prueba.ve` (validación RFC estricta).
   * **Contraseña:** Mínimo 8 caracteres (máx. 72 contra Bcrypt DoS), con mayúscula, minúscula, número y símbolo especial. Incluye barra de fortaleza y checklist interactivo en vivo.
4. **Casilla de Datos de Demostración (Opcional):**
   * Por defecto viene activada: `[X] Cargar datos de prueba de la red asistencial`.
   * Si la mantienes activa, aprovisiona de inmediato 3 ambulatorios en Mérida con su equipamiento y un operador auxiliar farmacéutico (`farmacia.llano@salud.prueba.ve` / `farmacia123`) para evaluar el control de acceso por roles (RBAC).
   * Si la desmarcas, obtienes un despliegue 100% en blanco de producción.
5. Haz clic en **"Crear cuenta principal"**.
6. El sistema ejecuta una transacción que:
   * Inserta al Administrador Central con `rol_id = 1` (`rol_admin`) y jurisdicción central (`centro_salud_id = NULL`).
   * Aprovisiona los ambulatorios y farmaceuta si se solicitó la demostración.
   * Registra el evento `INICIALIZAR_SISTEMA` en la bitácora forense WORM.
   * Inicia la sesión autenticada y redirige al panel de control `index.php`.
7. **Mecanismo de Auto-Bloqueo de Seguridad:** Tan pronto como existe al menos 1 usuario en la base de datos, `setup.php` queda **permanentemente deshabilitado**. Cualquier intento posterior de acceso es rechazado con un HTTP 302 hacia la pantalla de autenticación sobria (`login.php`).

---

### Opción B: Credenciales de Demostración Preconfiguradas
Si durante el Quick Start mantuviste activa la casilla de datos de prueba (o cargaste `02_seeders.sql`), dispones de estos dos perfiles para evaluar el sistema:

| Perfil / Rol | Correo Institucional | Contraseña | Alcance y Jurisdicción (RBAC) |
| :--- | :--- | :--- | :--- |
| **Administrador Central** | Tu correo registrado en `setup.php` (o `admin@salud.prueba.ve`) | Tu clave asignada (o `admin123`) | **Global:** Gestión de toda la red de ambulatorios, catálogos 3FN, operadores de seguridad, bitácora WORM y respaldos en caliente. |
| **Farmaceuta Operador** | `farmacia.llano@salud.prueba.ve` | `farmacia123` | **Aislado:** Restringido estrictamente a su ambulatorio asignado (*Ambulatorio Urbano III El Llano*). Bloqueo total de acceso a catálogos, operadores y respaldos. |

---

## 5. Batería de Pruebas Automatizadas

El sistema incluye scripts de verificación idempotentes para certificar la autenticación, integridad referencial y auditoría:

### 1. Test de Autenticación, Hashing Bcrypt y Aislamiento RBAC:
```bash
docker exec salud_php_apache php /var/www/html/scripts/test_auth.php
```

### 2. Test del Flujo CRUD y Auditoría Forense:
```bash
docker exec salud_php_apache php /var/www/html/scripts/test_crud.php
```

### 3. Test de Catálogos Maestros (3FN):
```bash
docker exec salud_php_apache php /var/www/html/scripts/test_maestras.php
```

### 4. Generación Masiva por Lotes (Prueba de Carga en InnoDB):
```bash
docker exec salud_php_apache php /var/www/html/scripts/poblar_medicamentos.php 1000
```

### 5. Limpieza de Registros de Prueba:
```bash
docker exec salud_php_apache php /var/www/html/scripts/poblar_medicamentos.php --limpiar
```

---

## 6. Arquitectura de Respaldos y Recuperación ante Desastres (Regla 3-2-1)

El sistema implementa una **estrategia de defensa en profundidad en 3 niveles concurrentes**, cumpliendo los lineamientos corporativos y la **Regla 3-2-1** (3 copias de los datos, 2 soportes distintos, 1 copia externa o fuera del sitio):

```
┌────────────────────────────────────────────────────────────────────────────┐
│ NIVEL 1: INFRAESTRUCTURA / DOCKER (Alta Disponibilidad Inmediata)          │
│ • Snapshot MVCC con mariadb-dump --single-transaction --quick              │
│ • Credenciales aisladas sin exposición en proceso del SO (ps aux)          │
└───────────────────────────────────────┬────────────────────────────────────┘
                                        │ Flujo binario seguro
                                        ▼
┌────────────────────────────────────────────────────────────────────────────┐
│ NIVEL 2: SISTEMA OPERATIVO / DEMONIO CRON (Recuperación ante Desastres)    │
│ • Escritura atómica (.tmp -> .gz) + Firma Criptográfica SHA-256            │
│ • Poda y Retención de 7 días (Pruning) para prevenir saturación de disco   │
└───────────────────────────────────────┬────────────────────────────────────┘
                                        │ Catálogo y verificación
                                        ▼
┌────────────────────────────────────────────────────────────────────────────┐
│ NIVEL 3: APLICACIÓN WEB PHP (Gobernanza Sanitaria y Auditoría Forense)     │
│ • Descarga bajo demanda en streaming con huella fija O(1) de RAM (< 2 MB)  │
│ • Trazabilidad WORM inmutable en logs_auditoria (EXPORTAR_RESPALDO_BD)     │
└────────────────────────────────────────────────────────────────────────────┘
```

### Nivel 1: Capa de Motor RDBMS y Docker (Snapshot MVCC)
* Ejecución de `mariadb-dump` con snapshot transaccional repetible (`--single-transaction`), garantizando consistencia relacional sin bloquear las tablas (`LOCK TABLES`) y permitiendo que los ambulatorios sigan despachando medicamentos en tiempo real.
* Inclusión de todos los objetos de base de datos (`--routines`, `--triggers`, `--events`, `--hex-blob`).
* Credenciales seguras mediante `--defaults-extra-file` o paso por variable de entorno en subproceso (`MYSQL_PWD`), evitando la exposición de contraseñas en texto plano en la tabla de procesos (`ps aux`).

### Nivel 2: Demonio del Sistema Operativo (Scripting y Criptografía)
* **Escritura Atómica:** El volcado se genera primero en un archivo temporal (`.tmp`) y solo se comprime a `.sql.gz` al finalizar con éxito el volcado, previniendo respaldos corruptos o a medio escribir.
* **Integridad Criptográfica (SHA-256):** Cada respaldo genera un archivo `.sha256` complementario para certificar la no-manipulación de los datos conforme a estándares forenses.
* **Política de Retención (7 días):** Poda automática de copias de seguridad de más de 7 días mediante `find -mtime +7 -delete`.
* **Ejecución manual o en crontab:**
  ```bash
  # En Linux / WSL:
  bash scripts/daemon_respaldo.sh

  # En Windows PowerShell:
  powershell -ExecutionPolicy Bypass -File scripts/daemon_respaldo.ps1
  ```
* **Restauración con Verificación de Integridad:**
  El script de restauración comprueba automáticamente el hash SHA-256 antes de inyectar el SQL en el motor, rechazando archivos corruptos o alterados:
  ```bash
  bash scripts/restaurar.sh respaldos/respaldo_sicm_YYYYMMDD_HHMMSS.sql.gz
  ```

### Nivel 3: Capa Web de Gobernanza y Control Municipal
* **Streaming en memoria constante $\mathcal{O}(1)$:** En lugar de cargar megabytes o gigabytes en la memoria de PHP (`memory_limit`), el volcado se canaliza directamente hacia la salida HTTP con `proc_open()` y `fpassthru()`, consumiendo un búfer de menos de 2 MB de memoria RAM.
* **Trazabilidad Forense Obligatoria:** Antes de emitir el primer byte binario, el sistema registra obligatoriamente en `logs_auditoria` el evento `EXPORTAR_RESPALDO_BD` con la IP remota, agente de usuario y marca de tiempo.
* **Descarga directa:** Accesible desde el botón del encabezado o la pestaña de Auditoría en la interfaz web (`index.php?tab=auditoria`).

---

## 7. Licencia y Uso Académico

Proyecto desarrollado con fines académicos para la cátedra de **Bases de Datos**. Código abierto bajo licencia MIT.
