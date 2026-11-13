# Hoja de Ruta y Backlog de Mejoras Futuras — SICM
## Sistema Integrado de Control de Medicamentos

Este documento define la planificación estratégica y las especificaciones funcionales para las fases evolutivas del sistema SICM. Cada iniciativa ha sido concebida bajo los principios rectores de normalización estricta (3FN), integridad de datos en salud pública, auditoría forense inmutable y cumplimiento de la normativa sanitaria venezolana del Ministerio del Poder Popular para la Salud (MPPS).

---

## 1. Vinculación 1:1 entre Usuarios del Sistema y Médicos Prescriptores (Padrón MPPS)

### 1.1. Contexto y Justificación
Actualmente, el sistema gestiona de forma independiente la nómina de usuarios con credenciales de acceso (`usuarios`) y el directorio de profesionales de la salud autorizados para prescribir (`medicos`). Para blindar la responsabilidad médico-legal y la trazabilidad de cada orden médica, se requiere una relación de cardinalidad 1:1 estricta entre la cuenta de sesión activa y el médico prescriptor.

### 1.2. Alcance Funcional y Técnico
- **Flujo de Validación y Homologación Sanitaria:**
  - Todo usuario al que se le asigne el rol asistencial de «Médico» debe atravesar un proceso de vinculación previa con su expediente en el padrón de prescriptores.
  - Validación formal del número de matrícula ante el MPPS y el registro del Colegio de Médicos respectivo antes de otorgar privilegios de prescripción.
- **Emisión con Firma Digital y Sellado Institucional:**
  - Al generar un récipe médico (`prescripciones`), el sistema asociará de manera criptográfica la identidad del usuario en sesión (`id_usuario`) con el expediente médico (`id_medico`).
  - Incorporación de firma digital / hash de autenticidad SHA-256 generado con la clave de sesión y estampado cronológico del servidor, garantizando el no repudio de la orden médica.
- **Impacto en Base de Datos:**
  - Restricción de integridad referencial `UNIQUE (id_usuario)` en la tabla `medicos` para impedir que múltiples cuentas compartan una misma matrícula profesional.

---

## 2. Portal Público / Pantalla Principal Fuera de Sesión (Landing Pública)

### 2.1. Contexto y Justificación
Proveer a la ciudadanía y a los establecimientos de salud externos una pasarela de autoservicio y verificación abierta (sin necesidad de credenciales de acceso ni privilegios administrativos), facilitando la transparencia y la detección inmediata de récipes falsificados o adulterados.

### 2.2. Alcance Funcional y Técnico
- **Verificador Público de Autenticidad de Récipes:**
  - Interfaz pública para escanear códigos QR, códigos de barras o ingresar el identificador alfanumérico único de prescripción.
  - Consulta en tiempo real de la vigencia del récipe, estado de dispensación (Pendiente, Dispensado Parcial, Dispensado Total o Anulado/Vencido), médico emisor y centro de salud de procedencia, omitiendo datos sensibles de la historia clínica.
- **Consulta de Vademécum Municipal:**
  - Directorio público y actualizado de medicamentos e insumos esenciales aprobados en la red asistencial municipal, con detalle de su principio activo, concentración y presentación farmacéutica.
- **Directorio de Ambulatorios y Horarios de Atención:**
  - Mapa interactivo y listado institucional de centros asistenciales, ambulatorios y farmacias comunitarias adscritas.
  - Consulta de horarios de guardia, servicios médicos operativos y canales de atención ciudadana.

---

## 3. Gestión Avanzada de Transferencias Inter-Ambulatorios

### 3.1. Contexto y Justificación
La distribución equitativa de insumos críticos entre la red de ambulatorios exige un circuito formal de balanceo de existencias que garantice trazabilidad física, control de inocuidad y prevención de mermas o pérdidas en tránsito.

### 3.2. Alcance Funcional y Técnico
- **Circuito de Requisiciones y Aprobación Centralizada:**
  - Flujo de solicitudes de reposición entre almacén central y ambulatorios satélites con niveles de autorización por parte de la Coordinación de Salud Municipal.
  - Bloqueo preventivo de existencias en origen para evitar sobre-asignaciones concurrentes.
- **Guías Electrónicas de Remisión y Despacho:**
  - Generación de guías de traslado normalizadas con especificación de números de lote, fechas de caducidad, cantidades y precintos de seguridad del bulto o contenedor.
- **Control de Inocuidad y Cadena de Frío:**
  - Registro de parámetros de conservación térmica para fármacos termolábiles y biológicos (vacunas, insulinas), registrando temperatura de salida, rango admisible y verificación al arribo.
- **Recepción Asistida y Cuadre de Inventario:**
  - Verificación en destino mediante lectura óptica con confirmación de discrepancias (sobrantes/faltantes/averías) que actualice de forma atómica los kardex de ambos establecimientos.

---

## 4. Reportes Epidemiológicos y Estadísticos Avanzados

### 4.1. Contexto y Justificación
La información clínica y de consumo consolidada en el SICM constituye un insumo estratégico para la vigilancia epidemiológica de la salud pública regional y nacional.

### 4.2. Alcance Funcional y Técnico
- **Homologación y Clasificación CIE-10:**
  - Incorporación del catálogo estándar de la Clasificación Internacional de Enfermedades (CIE-10) en el diagnóstico de prescripción.
  - Generación automatizada de reportes periódicos (EPI-12 / EPI-15) requeridos por las direcciones regionales de epidemiología del MPPS.
- **Curvas de Consumo y Proyección de Demanda:**
  - Modelado de Consumo Promedio Mensual (CPM), rotación de inventarios y cálculo de stock de seguridad para evitar quiebres de existencias en patologías crónicas (hipertensión, diabetes, asma).
- **Sistema de Alertas Tempranas de Brotes Epidemiológicos:**
  - Algoritmo de detección de anomalías estadísticas basado en el incremento inusual de despachos de antimicrobianos, antipiréticos, antihistamínicos o sales de rehidratación en áreas geográficas específicas, emitiendo alertas preventivas a las autoridades sanitarias.

---

## 5. Notificaciones Automatizadas Multicanal

### 5.1. Contexto y Justificación
Optimizar la adherencia terapéutica y reducir el absentismo en el retiro de tratamientos médicos mediante la comunicación directa con el paciente a través de canales de mensajería instantánea y telefonía móvil.

### 5.2. Alcance Funcional y Técnico
- **Integración con Canales de Mensajería (WhatsApp Business API / SMS):**
  - Despacho automatizado de notificaciones al momento de emitirse un récipe médico, con el enlace de descarga o QR para su retiro en ventanilla.
  - Recordatorios de fechas límite de retiro de tratamientos antes de que opere la caducidad reglamentaria del récipe.
- **Consumo de la Arquitectura Normalizada (`paciente_contactos`):**
  - Empleo de la estructura relacional 3FN existente, consultando el número principal verificado y respetando el canal de preferencia del ciudadano (Teléfono Móvil, Correo Electrónico o Representante Legal).
- **Bitácora de Entrega y Privacidad de Datos:**
  - Registro auditable del envío y confirmación de entrega en la bitácora del sistema, respetando las normas de confidencialidad del historial clínico sin exponer diagnósticos en texto plano.
