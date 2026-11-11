-- ============================================================================
-- SISTEMA INTEGRADO DE CONTROL DE MEDICAMENTOS (SICM) - AMBULATORIOS MUNICIPALES
-- Archivo: docker/mysql/05_seeders.sql
-- Módulo: Carga Canónica de Datos Maestros, Roles, Permisos y Cuentas Iniciales
-- ============================================================================

USE control_medicamentos_red;

-- ============================================================================
-- 1. CATÁLOGOS BASE Y SEGURIDAD RBAC (3FN)
-- ============================================================================

-- 1.0 Identidad Institucional Parametrizable (White-labeling)
INSERT INTO configuracion_institucional (id, nombre_sistema, siglas_sistema, ente_rector, municipio, logotipo_ruta, dias_alerta_fefo)
VALUES (1, 'Sistema Integrado de Control de Medicamentos', 'SICM', 'Dirección de Salud Municipal', 'Municipio Baruta', NULL, 60)
ON DUPLICATE KEY UPDATE id=1;

-- 1.1 Roles de Usuario
INSERT INTO roles (id, nombre, es_personalizado, descripcion) VALUES 
(1, 'rol_admin', 0, 'Administrador Central de la Red Asistencial Municipal'),
(2, 'rol_farmaceuta', 0, 'Operador Farmacéutico Sectorial y Dispensación FEFO'),
(3, 'rol_auditor', 0, 'Auditor Sanitario y Supervisor de Control Forense')
ON DUPLICATE KEY UPDATE 
    nombre = VALUES(nombre), 
    es_personalizado = VALUES(es_personalizado), 
    descripcion = VALUES(descripcion);

-- 1.2 Módulos del Sistema
INSERT INTO modulos_sistema (id, codigo, nombre, descripcion, activo) VALUES
(1, 'CENTROS', 'Red de Ambulatorios y Centros de Salud', 'Gestión de infraestructura física, directores y capacidades asistenciales', 1),
(2, 'MEDICAMENTOS', 'Catálogo Maestro de Fármacos', 'Gestión de principios activos, concentraciones y requerimientos de cadena de frío', 1),
(3, 'INVENTARIO', 'Gestión de Lotes y Almacenamiento', 'Control de stock físico, fechas de caducidad e inspección FEFO', 1),
(4, 'DESPACHOS', 'Dispensación y Prescripciones Médicas', 'Validación médica de recetas y registro atómico de entregas a pacientes', 1),
(5, 'USUARIOS', 'Seguridad, Credenciales y RBAC', 'Administración de cuentas de usuario, roles y matriz de permisos dinámicos', 1),
(6, 'AUDITORIA', 'Bitácora Forense WORM y Auditoría Delta', 'Inspección de trazabilidad forense inmutable y cambios celda por celda', 1)
ON DUPLICATE KEY UPDATE 
    codigo = VALUES(codigo), 
    nombre = VALUES(nombre), 
    descripcion = VALUES(descripcion);

-- 1.3 Catálogo de Permisos Atómicos
INSERT INTO permisos (id, modulo_id, codigo, nombre, descripcion) VALUES
(1,  1, 'CENTROS_VER', 'Consultar ambulatorios', 'Permite visualizar el listado y detalle de los ambulatorios'),
(2,  1, 'CENTROS_CREAR', 'Registrar nuevo ambulatorio', 'Permite incorporar nuevos centros de salud a la red'),
(3,  1, 'CENTROS_EDITAR', 'Modificar datos de ambulatorio', 'Permite actualizar información operativa de centros'),
(4,  1, 'CENTROS_ESTADO', 'Desactivar/Reactivar ambulatorio', 'Permite dar de baja lógica o reactivar un ambulatorio'),
(5,  2, 'MED_VER', 'Consultar catálogo de medicamentos', 'Visualización de vademécum municipal'),
(6,  2, 'MED_CREAR', 'Registrar nuevo fármaco', 'Incorporación de nuevos medicamentos al vademécum'),
(7,  2, 'MED_EDITAR', 'Editar características de fármacos', 'Modificar forma, concentración o cadena de frío'),
(8,  3, 'INV_VER', 'Consultar existencias y lotes FEFO', 'Inspección de stock por ambulatorio y vencimientos'),
(9,  3, 'INV_REGISTRAR_LOTE', 'Ingresar nuevo lote al inventario', 'Recepción formal de lotes farmacéuticos'),
(10, 3, 'INV_AJUSTAR', 'Ajustar mermas de inventario', 'Reporte justificado de deterioros o averías'),
(11, 4, 'DESP_VER', 'Consultar despachos realizados', 'Histórico de suministros entregados por periodo'),
(12, 4, 'DESP_PROCESAR', 'Procesar dispensación FEFO', 'Ejecución de despachos mediante transacción atómica'),
(13, 5, 'USR_VER', 'Consultar usuarios y perfiles', 'Visualización de nómina de operadores del sistema'),
(14, 5, 'USR_GESTIONAR', 'Administrar usuarios y permisos', 'Creación, edición y asignación de roles RBAC'),
(15, 6, 'AUDIT_VER', 'Consultar bitácoras forenses', 'Acceso a la auditoría general y auditoría delta celda por celda'),
(16, 6, 'AUDIT_EXPORTAR', 'Exportar reportes de trazabilidad', 'Descarga de evidencias forenses sanitarias'),
(17, 4, 'PACIENTES_VER', 'Consultar padrón de pacientes', 'Visualización de datos de beneficiarios y vías de contacto'),
(18, 4, 'PACIENTES_GESTIONAR', 'Registrar y editar pacientes', 'Alta y actualización de datos de pacientes y contactos 1FN'),
(19, 4, 'RECIPES_VER', 'Consultar prescripciones médicas', 'Visualización de tratamientos autorizados y vigencias'),
(20, 4, 'RECIPES_GESTIONAR', 'Emitir y desactivar récipes', 'Registro de nuevas prescripciones y desactivación'),
(21, 1, 'MAESTRAS_GESTIONAR', 'Administrar catálogos maestros', 'Gestión de formas farmacéuticas, patologías CIE-10, tipos y recursos'),
(22, 5, 'CONFIG_SISTEMA', 'Configuración institucional', 'Ajuste de ente rector, siglas, logotipo y umbrales de alerta'),
(23, 5, 'SISTEMA_RESPALDO', 'Descargar respaldos de base de datos', 'Generación y exportación de snapshots calientes'),
(24, 1, 'CENTROS_VER_GLOBAL', 'Supervisión municipal intercentros', 'Capacidad de conmutar y supervisar todos los ambulatorios de la red')
ON DUPLICATE KEY UPDATE 
    modulo_id = VALUES(modulo_id),
    codigo = VALUES(codigo), 
    nombre = VALUES(nombre), 
    descripcion = VALUES(descripcion);

-- 1.4 Matriz de Permisos por Rol (rol_permisos)
INSERT INTO rol_permisos (rol_id, permiso_id) VALUES
-- Administrador: Acceso total a los 24 permisos
(1, 1), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8),
(1, 9), (1, 10), (1, 11), (1, 12), (1, 13), (1, 14), (1, 15), (1, 16),
(1, 17), (1, 18), (1, 19), (1, 20), (1, 21), (1, 22), (1, 23), (1, 24),
-- Farmaceuta: Consultas asistenciales, pacientes, récipes, ingreso de lotes y dispensación FEFO
(2, 1), (2, 5), (2, 8), (2, 9), (2, 11), (2, 12), (2, 17), (2, 18), (2, 19), (2, 20),
-- Auditor Sanitario: Supervisión global y consulta exhaustiva de auditoría
(3, 1), (3, 24), (3, 5), (3, 8), (3, 11), (3, 17), (3, 19), (3, 13), (3, 15), (3, 16)
ON DUPLICATE KEY UPDATE rol_id = VALUES(rol_id);

-- 1.5 Tipos de Documento Oficiales
INSERT INTO tipos_documento (id, codigo, nombre) VALUES 
(1, 'V', 'Venezolano'),
(2, 'E', 'Extranjero'),
(3, 'P', 'Pasaporte')
ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), nombre = VALUES(nombre);

-- 1.6 Catálogo de Vías de Contacto (1FN)
INSERT INTO tipos_contacto (id, codigo, nombre, descripcion) VALUES
(1, 'TEL_FIJO', 'Teléfono de Habitación / Fijo', 'Línea de telefonía fija de habitación o residencia'),
(2, 'TEL_MOVIL', 'Teléfono Celular / Personal', 'Línea móvil o celular personal'),
(3, 'WHATSAPP', 'WhatsApp Personal', 'Canal de mensajería instantánea'),
(4, 'EMAIL_ALTERNO', 'Correo Electrónico', 'Buzón de correo electrónico'),
(5, 'CONTACTO_EMERGENCIA', 'Contacto de Emergencia / Familiar', 'Teléfono de contacto de familiar o representante')
ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- 1.7 Formas Farmacéuticas
INSERT INTO formas_farmaceuticas (id, nombre) VALUES 
(1, 'Tableta / Comprimido'),
(2, 'Cápsula'),
(3, 'Jarabe / Solución Oral'),
(4, 'Suspensión'),
(5, 'Ampolla Inyectable')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- 1.8 Tipos de Centros de Salud (3FN)
INSERT INTO tipos_centro_salud (id, codigo, nombre, descripcion) VALUES
(1, 'AMB_URB_1', 'Ambulatorio Urbano I', 'Atención médica general y preventiva sectorial'),
(2, 'AMB_URB_2', 'Ambulatorio Urbano II', 'Medicina general y farmacia sectorial'),
(3, 'AMB_URB_3', 'Ambulatorio Urbano III', 'Centro de salud cabecera de red y distribución'),
(4, 'AMB_RUR', 'Ambulatorio Rural', 'Dispensario foráneo en parroquias rurales'),
(5, 'CLIN_MUN', 'Clínica Popular Municipal', 'Centro de atención especializada y resolución')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- 1.9 Recursos y Capacidades Operativas (N:M)
INSERT INTO recursos_operativos (id, codigo, nombre, descripcion, activo) VALUES
(1, 'CADENA_FRIO', 'Refrigeración / Cadena de Frío', 'Neveras grado médico certificadas para vacunas e insulinas (2°C - 8°C)', 1),
(2, 'PLANTA_ELEC', 'Planta Eléctrica de Emergencia', 'Generador diésel auxiliar para contingencias eléctricas y soporte de frío', 1),
(3, 'AMBULANCIA', 'Ambulancia de Soporte Vital', 'Unidad de traslado asistencial y atención médica prehospitalaria', 1),
(4, 'ESTERILIZACION', 'Central de Esterilización', 'Autoclave hospitalario para instrumental quirúrgico y odontológico', 1),
(5, 'FARMACIA_24H', 'Farmacia Nocturna / 24 Horas', 'Ventanilla de despacho continuo durante turnos nocturnos y festivos', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion), activo = VALUES(activo);

-- 1.10 Catálogo Clínico Parametrizable: Patologías Crónicas (3FN)
INSERT INTO patologias (id, codigo_cie10, nombre, cronica, descripcion, activo) VALUES
(1, 'I10', 'Hipertensión Arterial Primaria (Esencial)', 1, 'Presión arterial sistólica >= 140 mmHg y/o diastólica >= 90 mmHg de etiología primaria', 1),
(2, 'E11', 'Diabetes Mellitus Tipo 2', 1, 'Alteración metabólica de hiperglucemia crónica no dependiente primariamente de insulina', 1),
(3, 'E10', 'Diabetes Mellitus Tipo 1', 1, 'Destrucción autoinmune de células beta pancreáticas con requerimiento estricto de insulina', 1),
(4, 'J45', 'Asma Bronquial Crónica', 1, 'Inflamación crónica de vías respiratorias con episodios recurrentes de broncoespasmo', 1),
(5, 'E03', 'Hipotiroidismo Primario', 1, 'Déficit en la producción de hormonas tiroideas por afectación glandular', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), codigo_cie10 = VALUES(codigo_cie10);

-- ============================================================================
-- 2. CENTROS DE SALUD DE LA RED MUNICIPAL
-- ============================================================================
INSERT INTO centros_salud (id, codigo_sicm, nombre, tipo_centro_id, medico_director, horario_atencion, direccion, telefono_principal, activo) VALUES
(1, 'AMB-MER-001', 'Ambulatorio Urbano III El Llano', 3, 'Dr. Carlos Mendoza (MPPS 45.120)', '24 Horas / Emergencia Continua', 'Av. 3 Independencia, Sector El Llano, Mérida', '0274-2521100', 1),
(2, 'AMB-MER-002', 'Ambulatorio Urbano II Santa Rosa', 2, 'Dra. Elena Rivas (MPPS 52.880)', 'Lunes a Viernes 7:00 AM - 7:00 PM', 'Sector La Hechicera / Santa Rosa, Mérida', '0274-2443210', 1),
(3, 'AMB-MER-003', 'Ambulatorio Urbano III Belén', 3, 'Dr. Roberto Briceño (MPPS 39.410)', '24 Horas / Guardias Rotativas', 'Calle 16 entre Av. 7 y 8, Sector Belén, Mérida', '0274-2510988', 1)
ON DUPLICATE KEY UPDATE 
    nombre = VALUES(nombre), 
    tipo_centro_id = VALUES(tipo_centro_id),
    medico_director = VALUES(medico_director),
    horario_atencion = VALUES(horario_atencion),
    direccion = VALUES(direccion),
    telefono_principal = VALUES(telefono_principal);

-- 2.1 Normalización 1FN: Directorio Telefónico de los Centros de Salud
INSERT INTO telefonos_centro_salud (id, centro_salud_id, tipo_contacto_id, numero, es_principal, observaciones) VALUES
(1, 1, 1, '0274-2521100', 1, 'Centralita Principal Asistencial'),
(2, 1, 2, '0414-7501122', 0, 'Línea de Guardia Médica Nocturna'),
(3, 2, 1, '0274-2443210', 1, 'Recepción y Farmacia Sectorial'),
(4, 3, 1, '0274-2510988', 1, 'Dirección y Sala de Emergencia')
ON DUPLICATE KEY UPDATE numero = VALUES(numero), es_principal = VALUES(es_principal);

-- 2.2 Asignación N:M de Recursos y Capacidades Operativas a Centros
INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo, detalle_capacidad) VALUES
-- El Llano: Cadena de Frío certificada, Planta Eléctrica y Ambulancia
(1, 1, 1, 'Refrigerador médico Haier Biomedical 350L (2°C - 8°C)'),
(1, 2, 1, 'Generador Cummins 30 kVA Diésel con conmutación ATS'),
(1, 3, 1, 'Unidad de Rescate Soporte Avanzado Toyota Land Cruiser'),

-- Santa Rosa: Cadena de Frío y Farmacia 24 Horas
(2, 1, 1, 'Refrigerador vertical 180L con monitoreo digital'),
(2, 5, 1, 'Dispensación ambulatoria continua'),

-- Belén: Cadena de Frío, Planta Eléctrica y Central Esterilización
(3, 1, 1, 'Nevera horizontal grado farmacéutico 300L'),
(3, 2, 1, 'Generador auxiliar 25 kVA'),
(3, 4, 1, 'Autoclave digital a vapor 50L')
ON DUPLICATE KEY UPDATE operativo = VALUES(operativo), detalle_capacidad = VALUES(detalle_capacidad);

-- ============================================================================
-- 3. USUARIOS INICIALES (RBAC)
-- ============================================================================
INSERT INTO usuarios (id, nombre, email, password_hash, rol_id, centro_salud_id, activo) VALUES 
(1, 'Administrador Municipal', 'admin@salud.prueba.ve', SHA2('admin123', 256), 1, NULL, 1),
(2, 'Lcda. Elena Farmaceuta', 'farmacia.llano@salud.prueba.ve', SHA2('farmacia123', 256), 2, 1, 1),
(3, 'Dr. Andrés Auditor', 'auditor@salud.prueba.ve', SHA2('auditor123', 256), 3, NULL, 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = VALUES(email), rol_id = VALUES(rol_id);

-- ============================================================================
-- 4. CATÁLOGO DE MEDICAMENTOS (VADEMÉCUM CANÓNICO)
-- ============================================================================
INSERT INTO medicamentos (id, nombre_generico, concentracion, forma_farmaceutica_id, requiere_cadena_frio, stock_minimo_sugerido, activo) VALUES
(1, 'Losartán Potásico', '50mg', 1, 0, 30, 1),
(2, 'Metformina Clorhidrato', '850mg', 1, 0, 40, 1),
(3, 'Insulina NPH Humana', '100UI/ml', 5, 1, 15, 1), -- Requiere Cadena de Frío Estricta
(4, 'Amlodipino Besilato', '10mg', 1, 0, 25, 1),
(5, 'Salbutamol Sulfato', '100mcg/dosis', 4, 0, 20, 1)
ON DUPLICATE KEY UPDATE 
    nombre_generico = VALUES(nombre_generico), 
    concentracion = VALUES(concentracion), 
    requiere_cadena_frio = VALUES(requiere_cadena_frio),
    stock_minimo_sugerido = VALUES(stock_minimo_sugerido);

-- ============================================================================
-- 5. PACIENTES Y NORMALIZACIÓN 1FN DE CONTACTOS
-- ============================================================================
INSERT INTO pacientes (id, tipo_documento_id, cedula, nombre_completo, fecha_nacimiento, direccion_residencia) VALUES
(1, 1, '14285714', 'Juan Carlos Morales Dávila', '1980-05-14', 'Av. 4 con Calle 22, El Llano, Mérida'),
(2, 1, '18952301', 'María Auxiliadora Peña Rangel', '1988-11-20', 'Sector La Vuelta de Lola, Santa Rosa, Mérida'),
(3, 1, '11400219', 'Pedro José Albarrán Contreras', '1975-02-03', 'Sector Hoyada de Milla, Belén, Mérida')
ON DUPLICATE KEY UPDATE nombre_completo = VALUES(nombre_completo);

-- 5.1 Directorio 1FN de Contactos de Pacientes
INSERT INTO paciente_contactos (id, paciente_id, tipo_contacto_id, valor, es_principal, nombre_contacto_emergencia) VALUES
(1, 1, 2, '0414-7223344', 1, 'Paciente Directo'),
(2, 2, 2, '0424-7119988', 1, 'Paciente Directo'),
(3, 3, 1, '0274-2661020', 1, 'Residencia Familiar'),
(4, 3, 5, '0412-5509911', 0, 'Rosa Albarrán (Hija / Contacto de Emergencia)')
ON DUPLICATE KEY UPDATE valor = VALUES(valor), es_principal = VALUES(es_principal);

-- ============================================================================
-- 6. TRATAMIENTOS AUTORIZADOS (RÉCIPES VIGENTES)
-- ============================================================================
INSERT INTO tratamientos_autorizados (id, paciente_id, medicamento_id, patologia_id, dosis_mensual, fecha_emision_recipe, fecha_vencimiento_recipe, medico_prescriptor, activo) VALUES
-- Paciente 1 (Juan Morales): Hipertensión -> Losartán 50mg (30 comp/mes)
(1, 1, 1, 1, 30, '2026-09-01', '2027-09-01', 'Dr. Carlos Mendoza (MPPS 45.120)', 1),
-- Paciente 2 (María Peña): Diabetes Tipo 2 -> Metformina 850mg (60 comp/mes)
(2, 2, 2, 2, 60, '2026-08-15', '2027-08-15', 'Dra. Elena Rivas (MPPS 52.880)', 1),
-- Paciente 3 (Pedro Albarrán): Diabetes Tipo 1 -> Insulina NPH (2 frascos/mes)
(3, 3, 3, 3, 2, '2026-10-01', '2027-10-01', 'Dr. Roberto Briceño (MPPS 39.410)', 1)
ON DUPLICATE KEY UPDATE 
    dosis_mensual = VALUES(dosis_mensual), 
    fecha_vencimiento_recipe = VALUES(fecha_vencimiento_recipe),
    activo = VALUES(activo);

-- ============================================================================
-- 7. LOTES DE INVENTARIO (APROVISIONAMIENTO INICIAL FEFO)
-- ============================================================================
INSERT INTO lotes_inventario (id, centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_actual, usuario_id) VALUES
-- Ambulatorio El Llano (Centro 1)
(1, 1, 1, 'LT-LOS-2026-A', '2027-12-31', 500, 2),
(2, 1, 2, 'LT-MET-2026-B', '2027-08-15', 300, 2),
(3, 1, 3, 'LT-INS-2026-C', '2027-05-30', 80,  2), -- Insulina validada por trigger contra recurso CADENA_FRIO

-- Ambulatorio Santa Rosa (Centro 2)
(4, 2, 1, 'LT-LOS-SR-01', '2027-10-20', 250, 2),
(5, 2, 2, 'LT-MET-SR-02', '2027-11-10', 180, 2),

-- Ambulatorio Belén (Centro 3)
(6, 3, 1, 'LT-LOS-BL-01', '2027-09-15', 400, 2)
ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual);

-- ============================================================================
-- 8. NOTIFICACIONES DEL SISTEMA Y ALERTAS PREVENTIVAS
-- ============================================================================
INSERT INTO notificaciones_sistema (id, usuario_id, centro_salud_id, tipo, titulo, mensaje, leida, created_at) VALUES
(1, 1, 1, 'SISTEMA', 'Despliegue Exitoso de Red SICM', 'La base de datos municipal y el motor de blindaje sanitario se encuentran operativos en estado de máxima integridad.', 0, NOW()),
(2, 2, 1, 'SISTEMA', 'Vademécum Inicial Habilitado', 'Se han aprovisionado los lotes iniciales de Losartán, Metformina e Insulina con soporte de cadena de frío.', 0, NOW())
ON DUPLICATE KEY UPDATE titulo = VALUES(titulo);

-- ============================================================================
-- 9. LOG INICIAL DE AUDITORÍA FORENSE WORM
-- ============================================================================
INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles_json, ip_origen, user_agent, fecha_hora) VALUES
(1, 'INICIALIZAR_SISTEMA', 'sistema', 1, 
 JSON_OBJECT(
     'evento', 'Despliegue integral de red asistencial municipal',
     'centros_iniciales', 3,
     'recursos_maestros', 5,
     'modulos_rbac', 6,
     'permisos_totales', 16,
     'patologias_3fn', 5,
     'version_esquema', '2.0.0-3FN-WORM',
     'estado', 'OPERATIVO'
 ), 
 '127.0.0.1', 'DatabaseSeeder/05_seeders.sql', NOW());
