# Sistema de Control y Trazabilidad de Medicamentos (SICM)

> **Red Municipal de Salud | Proyecto de Bases de Datos**  
> **Estudiante:** Mikael Pizzi (C.I. 27.777.243)  
> **Motor:** MariaDB 11.4 / MySQL 8.0 (Storage Engine: InnoDB)  
> **Stack:** PHP 8.3 (Nativo / PDO) + Vanilla JavaScript (ES6+) + Bootstrap 5 + Docker  

---

## 1. Descripción del Proyecto

El **SICM (Sistema de Información para el Control de Medicamentos)** es una plataforma técnica diseñada para regular la entrega controlada de insumos farmacológicos para patologías crónicas en la red de ambulatorios municipales. 

El diseño del sistema aborda tres desafíos críticos de ingeniería de datos:
1. **Prevención de mermas por caducidad:** Implementación de consultas y vistas bajo criterio **FEFO** (*First Expired, First Out*).
2. **Candado contra retiros duplicados:** Bloqueo transaccional desde el motor RDBMS mediante claves compuestas únicas (`UNIQUE KEY (tratamiento_id, periodo_mes_anio)`), inmune a condiciones de carrera (*Race Conditions*).
3. **Trazabilidad sanitaria sin borrado destructivo:** Ciclo de vida basado en *Soft Delete* (`deleted_at`) y auditoría de autoría (`actualizado_por_id`).

---

## 2. Decisiones de Arquitectura de Datos (ADR)

* **Normalización en Tercera Forma Normal (3FN):**  
  Se eliminaron dependencias transitivas y multivaluadas mediante catálogos maestros (`formas_farmaceuticas`, `tipos_documento`, `tipos_contacto`, `roles`). Se prohíbe el uso de tipos `ENUM` para clasificaciones que requieran mantenimiento administrativo sin adquirir bloqueos de metadatos (*Metadata Locks*).
* **Eficiencia en Páginas de Disco (InnoDB 16 KB):**  
  Ancho de fila reducido y tipos de datos atómicos para maximizar la densidad de registros por bloque en el *Buffer Pool* y minimizar operaciones de Entrada/Salida (I/O).
* **Vistas como Capa de Abstracción:**  
  La consulta del catálogo se expone mediante la vista SQL `vw_catalogo_medicamentos`. El backend (PHP) consulta directamente la tabla virtual, desacoplando los cambios físicos del esquema de las consultas de aplicación.
* **Índices en Árbol B+ y Búsqueda por Prefijo:**  
  La búsqueda en servidor aplica coincidencia por prefijo (`LIKE 'termino%'`), permitiendo al optimizador navegar el árbol B+ en tiempo logarítmico $\mathcal{O}(\log N)$ evitando un *Full Table Scan*.
* **Política de Auto-Registro:**  
  **Deshabilitado por diseño.** Al tratarse de un sistema cerrado gubernamental para insumos sanitarios críticos, el alta de operadores es potestad exclusiva del Administrador Central para salvaguardar el no repudio.

---

## 3. Estructura del Repositorio

```text
sistema-distribucion/
├── docker/
│   ├── mysql/
│   │   ├── 01_schema.sql         # Esquema DDL en 3FN, vistas, triggers y funciones
│   │   └── 02_seeders.sql        # Semillas de parametrización y datos iniciales
│   └── php/
│       └── Dockerfile            # Imagen base PHP 8.3 Apache con extensiones PDO
├── docker-compose.yml            # Orquestación de contenedores y red interna
├── scripts/
│   └── poblar_medicamentos.php   # Proxy CLI para ejecuciones directas
├── src/
│   ├── config/
│   │   └── db.php                # Conexión nativa PDO con manejo de excepciones
│   ├── controllers/
│   │   └── api.php               # Controlador CRUD con paginación server-side
│   ├── public/
│   │   └── js/
│   │       └── app.js            # Controlador cliente Vanilla JS (Debounce 300ms)
│   ├── scripts/
│   │   └── poblar_medicamentos.php # Generador masivo por lotes (Batch Insert)
│   └── index.php                 # Interfaz técnica de alta densidad
├── .gitignore
└── README.md
```

---

## 4. Despliegue Rápido con Docker

### Requisitos
* Docker Engine 24+ y Docker Compose v2+

### Pasos de inicialización

1. **Clonar e iniciar los contenedores:**
   ```bash
   docker compose up -d --build
   ```

2. **Acceso al sistema:**
   * **Aplicación Web:** `http://localhost:8080`
   * **Base de Datos (Puerto expuesto):** `localhost:3306`
     * Base de datos: `control_medicamentos_red`
     * Usuario: `app_salud_user`
     * Contraseña: `app_password_seguro_2026`

3. **Detener el entorno:**
   ```bash
   docker compose down
   ```

---

## 5. Herramienta CLI: Generación Masiva y Limpieza

El repositorio incluye un generador masivo en CLI diseñado para pruebas de carga y escalabilidad bajo estándar InnoDB:

### Poblar con 10.000 medicamentos:
Aplica *Multi-row Prepared Statements* (500 filas por lote / 2.500 parámetros) en una única transacción ACID, alcanzando tasas de inserción superiores a 40.000 filas/segundo:
```bash
docker exec salud_php_apache php /var/www/html/scripts/poblar_medicamentos.php 10000
```

### Limpiar registros generados (Restaurar catálogo base):
Elimina exclusivamente los registros generados masivamente y restablece el contador `AUTO_INCREMENT`:
```bash
docker exec salud_php_apache php /var/www/html/scripts/poblar_medicamentos.php --limpiar
```

---

## 6. Licencia y Uso Académico

Proyecto desarrollado con fines académicos para la materia de **Bases de Datos**. Código bajo licencia MIT.

