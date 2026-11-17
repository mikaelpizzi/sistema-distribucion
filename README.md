# Municipal Medication Distribution & Inventory Control System (SICM)

A relational, 3NF-normalized pharmaceutical inventory and dispensation platform designed for municipal public health networks. Built with engine-level integrity guarantees, strict ACID transaction boundaries, and audit immutability in MariaDB.

---

## Architecture & Core Guarantees

* **FEFO Inventory Dispatching:** Automated First-Expired, First-Out batch allocation via composite indexes `(medicamento_id, fecha_vencimiento, stock_actual)` to eliminate product expiration waste.
* **Anti-Fraud Dispensation Lock:** Relational constraints and atomic transaction boundaries prevent duplicate monthly medication claims across different health centers.
* **Dynamic 3NF RBAC:** Granular role-based access control with decoupled permission mappings (`roles`, `permisos`, `rol_permisos`), allowing dynamic runtime authorization without schema DDL locks.
* **Immutable Audit Trail (WORM):** Database triggers enforce Write-Once-Read-Many audit logs (`logs_auditoria`) capturing old/new JSON state deltas and execution context. Hard deletes are prohibited across operational tables.
* **First Normal Form (1NF) Contact Entity:** Multi-contact patient records and healthcare facility capabilities are modeled vertically through junction tables rather than serialized lists.

---

## Technology Stack

| Layer | Technology | Key Implementation Details |
| :--- | :--- | :--- |
| **Database** | MariaDB 11.4 / MySQL 8.0 | InnoDB, strict SQL mode, foreign keys, stored routines, automated triggers |
| **Backend** | PHP 8.3 | Native PDO with prepared statements, explicit transaction management (`BEGIN / COMMIT / ROLLBACK`), zero ORM overhead |
| **Frontend** | Vanilla JS (ES6+) + Bootstrap 5.3 | Modular DOM rendering, modal workflows, zero external framework dependencies |
| **Runtime** | Docker & Docker Compose | Multi-container setup (`salud_php_apache`, `salud_maria_db`) with healthchecks and isolated networking |

---

## Database Architecture

The schema is organized into modular initialization scripts under `docker/mysql/`:

* `01_tables.sql`: Core 3NF tables, foreign key constraints, and column-level `CHECK` definitions.
* `02_routines.sql`: Stored procedures encapsulating atomic operations (batch intake, dispensation processing).
* `03_triggers.sql`: Engine-level business rules, inventory adjustments, and immutable audit logging.
* `04_views.sql`: Materialized abstractions for active stock, expiration monitoring, and dispensing metrics.
* `05_seeders.sql`: Initial system catalogues, permissions matrix, and development test records.
* `06_privileges.sql`: Principle of least privilege with distinct database roles for application runtime vs. administrative migrations.

---

## Repository Structure

```text
sistema-distribucion/
├── docker/
│   ├── apache/             # Custom Apache virtual host configuration
│   └── mysql/              # Ordered SQL initialization layers (01 to 06)
├── docker-compose.yml      # Service definitions, volumes, and healthchecks
├── Dockerfile              # PHP 8.3 Apache base image with PDO extensions
├── src/
│   ├── config/             # Database connection handler (PDO singleton)
│   ├── controllers/        # Request controllers and business transactions
│   ├── models/             # Data access layers with explicit SQL queries
│   ├── views/              # Interface views, modals, and layouts
│   ├── assets/             # Stylesheets and client-side scripts
│   └── index.php           # Front controller and route dispatcher
└── tests/                  # Integrity verification scripts
```

---

## Getting Started

### Prerequisites

* [Docker](https://www.docker.com/) (version 24.0 or higher)
* [Docker Compose](https://docs.docker.com/compose/) (version 2.20 or higher)

### Setup & Run

1. Clone the repository:
   ```bash
   git clone https://github.com/mikaelpizzi/sistema-distribucion.git
   cd sistema-distribucion
   ```

2. Start the container environment:
   ```bash
   docker compose up -d --build
   ```

3. Access the web interface:
   - URL: `http://localhost:8080`
   - Database Adminer / Direct Access: `localhost:3306`

### Default Credentials (Development Environment)

| Role | Email | Password | Scope |
| :--- | :--- | :--- | :--- |
| **Central Administrator** | `admin@salud.gob.ve` | `prueba123` | Global jurisdiction across all network facilities |
| **Pharmacist** | `farmacia.norte@salud.gob.ve` | `prueba123` | Restricted to Northern Ambulatory inventory and dispensation |
| **Sanitary Auditor** | `auditor@salud.gob.ve` | `prueba123` | Read-only inspection of WORM audit logs and system deltas |

---

## Development Workflow

This repository follows the **GitFlow** branching strategy:
* `main`: Stable, production-ready baseline.
* `develop`: Active integration branch for all new features, refactoring, and testing.
* `release/*`: Frozen delivery snapshots for formal milestone reviews.

---

## Author

* **Mikael Pizzi** — Computer Engineering / Relational Database Systems
