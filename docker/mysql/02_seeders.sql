USE control_medicamentos_red;

-- ============================================================================
-- 1. ROLES DEL SISTEMA (3FN)
-- ============================================================================
INSERT INTO roles (id, nombre) VALUES 
(1, 'rol_admin'),
(2, 'rol_farmaceuta')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ============================================================================
-- 2. TIPOS DE DOCUMENTO OFICIALES
-- ============================================================================
INSERT INTO tipos_documento (id, codigo, nombre) VALUES 
(1, 'V', 'Venezolano'),
(2, 'E', 'Extranjero'),
(3, 'P', 'Pasaporte')
ON DUPLICATE KEY UPDATE codigo = VALUES(codigo);

-- ============================================================================
-- 3. FORMAS FARMACÉUTICAS
-- ============================================================================
INSERT INTO formas_farmaceuticas (id, nombre) VALUES 
(1, 'Tableta / Comprimido'),
(2, 'Cápsula'),
(3, 'Jarabe / Solución Oral'),
(4, 'Suspensión'),
(5, 'Ampolla Inyectable')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

-- ============================================================================
-- 4. TIPOS DE CENTRO DE SALUD (Catálogo Maestro Parametrizable)
-- ============================================================================
INSERT INTO tipos_centro_salud (id, codigo, nombre, descripcion) VALUES
(1, 'AMB_URB_1', 'Ambulatorio Urbano I', 'Atención médica general y preventiva sectorial'),
(2, 'AMB_URB_2', 'Ambulatorio Urbano II', 'Medicina general y farmacia sectorial'),
(3, 'AMB_URB_3', 'Ambulatorio Urbano III', 'Centro de salud cabecera de red y distribución'),
(4, 'AMB_RUR', 'Ambulatorio Rural', 'Dispensario foráneo en parroquias rurales'),
(5, 'CLIN_MUN', 'Clínica Popular Municipal', 'Centro de atención especializada y resolución')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion);

-- ============================================================================
-- 5. RECURSOS Y CAPACIDADES OPERATIVAS (Catálogo Maestro N:M)
-- ============================================================================
INSERT INTO recursos_operativos (id, codigo, nombre, descripcion, activo) VALUES
(1, 'CADENA_FRIO', 'Refrigeración / Cadena de Frío', 'Neveras grado médico certificadas para vacunas e insulinas (2°C - 8°C)', 1),
(2, 'PLANTA_ELEC', 'Planta Eléctrica de Emergencia', 'Generador diésel auxiliar para contingencias eléctricas y soporte de frío', 1),
(3, 'AMBULANCIA', 'Ambulancia de Soporte Vital', 'Unidad de traslado asistencial y atención médica prehospitalaria', 1),
(4, 'ESTERILIZACION', 'Central de Esterilización', 'Autoclave hospitalario para instrumental quirúrgico y odontológico', 1),
(5, 'FARMACIA_24H', 'Farmacia Nocturna / 24 Horas', 'Ventanilla de despacho continuo durante turnos nocturnos y festivos', 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), descripcion = VALUES(descripcion), activo = VALUES(activo);

-- ============================================================================
-- 6. CENTROS DE SALUD INICIALES (Red Municipal de Salud de Mérida)
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

-- ============================================================================
-- 7. ASIGNACIÓN N:M DE RECURSOS A CENTROS (centro_recursos)
-- ============================================================================
INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo, detalle_capacidad) VALUES
-- El Llano: Cadena de Frío (350L), Planta Eléctrica (30 kVA), Ambulancia
(1, 1, 1, 'Refrigerador médico Haier Biomedical 350L'),
(1, 2, 1, 'Generador Cummins 30 kVA Diésel con ATS'),
(1, 3, 1, 'Unidad de Rescate Soporte Avanzado Toyota'),

-- Santa Rosa: Cadena de Frío (180L), Farmacia 24H
(2, 1, 1, 'Refrigerador vertical 180L con monitoreo digital'),
(2, 5, 1, 'Dispensación ambulatoria continua'),

-- Belén: Cadena de Frío (300L), Planta Eléctrica (25 kVA), Central Esterilización
(3, 1, 1, 'Nevera horizontal grado farmacéutico 300L'),
(3, 2, 1, 'Generador auxiliar 25 kVA'),
(3, 4, 1, 'Autoclave digital a vapor 50L')
ON DUPLICATE KEY UPDATE operativo = VALUES(operativo), detalle_capacidad = VALUES(detalle_capacidad);

-- ============================================================================
-- 8. USUARIOS INICIALES (ADMINISTRADOR CENTRAL Y FARMACEUTA DE SECTOR)
-- Hash generado con función nativa de MySQL/MariaDB: SHA2('pass', 256)
-- ============================================================================
INSERT INTO usuarios (id, nombre, email, password_hash, rol_id, centro_salud_id, activo) VALUES 
(1, 'Administrador Municipal', 'admin@salud.prueba.ve', SHA2('admin123', 256), 1, NULL, 1),
(2, 'Lcda. Elena Farmaceuta', 'farmacia.llano@salud.prueba.ve', SHA2('farmacia123', 256), 2, 1, 1)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), email = VALUES(email);

-- ============================================================================
-- 9. LOG INICIAL DE AUDITORÍA FORENSE
-- ============================================================================
INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, detalles_json, ip_origen, fecha_hora) VALUES
(1, 'INICIALIZAR_SISTEMA', 'centros_salud', 1, '{"evento":"Despliegue inicial de red asistencial municipal","centros_iniciales":3,"recursos_maestros":5,"estado":"OPERATIVO"}', '127.0.0.1', NOW());