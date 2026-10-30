USE control_medicamentos_red;

-- 1. Roles parametrizados
INSERT INTO roles (id, codigo, nombre, descripcion, permisos_json) VALUES
(1, 'ROL_ADMIN', 'Administrador Central', 'Control global de red municipal y catálogos', '{"alcance": "global", "modulos": ["usuarios", "medicamentos", "lotes", "despachos", "auditoria", "configuracion"]}'),
(2, 'ROL_FARMACEUTA', 'Farmaceuta de Ambulatorio', 'Operador acotado a su centro de salud asignado', '{"alcance": "centro_salud", "modulos": ["lotes", "despachos", "pacientes"]}');

-- 2. Tipos de Documento (Sin quemar V/E/J en código)
INSERT INTO tipos_documento (id, codigo, nombre, patron_regex) VALUES
(1, 'V', 'Cédula Venezolana', '^[0-9]{6,9}$'),
(2, 'E', 'Cédula Extranjero', '^[0-9]{6,10}$'),
(3, 'J', 'RIF Jurídico', '^[0-9]{8,10}$'),
(4, 'G', 'RIF Gubernamental', '^[0-9]{8,10}$');

-- 3. Tipos de Contacto (Para 1FN en contactos de pacientes)
INSERT INTO tipos_contacto (id, codigo, nombre) VALUES
(1, 'MOVIL', 'Teléfono Móvil / WhatsApp'),
(2, 'LOCAL', 'Teléfono Fijo / Habitación'),
(3, 'EMAIL', 'Correo Electrónico'),
(4, 'DIRECCION', 'Dirección de Habitación');

-- 4. Formas Farmacéuticas
INSERT INTO formas_farmaceuticas (id, nombre, unidad_base) VALUES
(1, 'Tableta Recubierta', 'mg'),
(2, 'Cápsula Blanda', 'mg'),
(3, 'Solución Inyectable (Pluma/Vial)', 'UI/ml'),
(4, 'Inhalador de Dosis Medida', 'mcg'),
(5, 'Suspensión Oral', 'mg/5ml');

-- 5. Configuración Inicial del Sistema (Wizard + Mensajes Personalizables)
INSERT INTO configuraciones_sistema (clave, valor_json, descripcion) VALUES
('institucion_info', '{"nombre": "Dirección Municipal de Salud - Mérida", "modo_registro_publico": false, "wizard_completado": true}', 'Parámetros del ente rector y bloqueo de auto-registro público'),
('mensajes_personalizados', '{"es": {"bloqueo_doble_retiro": "ALERTA SANITARIA: El paciente ya retiró la dosis mensual autorizada en este período.", "exito_despacho": "Despacho procesado aplicando prioridad FEFO."}}', 'Mensajes personalizables por el cliente sin tocar código');

-- 6. Centros de Salud (Ambulatorios de Mérida)
INSERT INTO centros_salud (id, codigo_sicm, nombre, direccion, telefono_principal) VALUES
(1, 'AMB-MER-001', 'Ambulatorio Urbano III El Llano', 'Av. 3 Independencia, Sector El Llano, Mérida', '0274-2521100'),
(2, 'AMB-MER-002', 'Ambulatorio Urbano II Santa Rosa', 'Sector La Hechicera / Santa Rosa, Mérida', '0274-2443210'),
(3, 'AMB-MER-003', 'Ambulatorio Urbano III Belén', 'Calle 16 entre Av. 7 y 8, Sector Belén, Mérida', '0274-2510988');

-- 7. Usuarios Iniciales (Password cifrado para ambos: Admin123*)
INSERT INTO usuarios (id, tipo_documento_id, numero_documento, nombre_completo, email, password_hash, rol_id, centro_salud_id) VALUES
(1, 1, '27777243', 'Mikael Pizzi (Admin Central)', 'admin@saludmerida.gob.ve', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, NULL),
(2, 1, '19888444', 'Dra. Elena Rojas (Farmaceuta Santa Rosa)', 'farmacia.santarosa@saludmerida.gob.ve', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 2, 2);

-- 8. Catálogo Inicial de Medicamentos Crónicos
INSERT INTO medicamentos (id, codigo_atc, nombre_generico, concentracion, forma_farmaceutica_id, requiere_cadena_frio, metadatos_json, actualizado_por_id) VALUES
(1, 'C09CA01', 'Losartán Potásico', '50mg', 1, 0, '{"patologia": "Hipertensión Arterial", "caja_unidades": 30}', 1),
(2, 'A10BA02', 'Metformina Clorhidrato', '850mg', 1, 0, '{"patologia": "Diabetes Mellitus Tipo 2", "caja_unidades": 30}', 1),
(3, 'A10AE04', 'Insulina Glargina', '100UI/ml', 3, 1, '{"patologia": "Diabetes Insulinodependiente", "temp_max_c": 8}', 1),
(4, 'C08CA01', 'Amlodipino Besilato', '10mg', 1, 0, '{"patologia": "Hipertensión Arterial / Angina", "caja_unidades": 30}', 1),
(5, 'R03AC02', 'Salbutamol Sulfato', '100mcg', 4, 0, '{"patologia": "Asma Bronquial Crónica", "dosis_inhalador": 200}', 1);

-- 9. Lotes de Inventario (Con distintas fechas para demostrar el ordenamiento FEFO)
INSERT INTO lotes_inventario (centro_salud_id, medicamento_id, numero_lote, fecha_vencimiento, cantidad_inicial, cantidad_actual, registrado_por_id) VALUES
(2, 1, 'LOTE-LOS-2026A', DATE_ADD(CURDATE(), INTERVAL 18 DAY), 500, 120, 2), -- Crítico FEFO (<30 días)
(2, 1, 'LOTE-LOS-2027B', DATE_ADD(CURDATE(), INTERVAL 240 DAY), 1000, 1000, 2), -- Óptimo
(2, 2, 'LOTE-MET-2026X', DATE_ADD(CURDATE(), INTERVAL 65 DAY), 800, 450, 2), -- Prioridad salida (<90 días)
(1, 3, 'LOTE-INS-2026C', DATE_ADD(CURDATE(), INTERVAL 120 DAY), 200, 180, 1);

-- 10. Log inicial de instalación
INSERT INTO logs_auditoria (usuario_id, accion, tabla_afectada, registro_id, ip_origen, user_agent, detalles_json) VALUES
(1, 'SYSTEM_INIT', 'configuraciones_sistema', 1, '127.0.0.1', 'Seeder/CLI', '{"evento": "Inicialización de esquema 3FN y catálogos maestros"}');