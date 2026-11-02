<?php
// src/setup.php
// Asistente de Inicialización de Primer Arranque (Setup Inicial)
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';

$pdo = getDB();

// 1. Si ya existen usuarios registrados, el setup está permanentemente bloqueado por seguridad
if (contarUsuariosRegistrados($pdo) > 0) {
    header('Location: login.php');
    exit;
}

$error = null;

// 2. Procesar Inicialización (Validación Estricta en Backend)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitización y prevención de abuso de inputs
    $nombre     = trim(filter_var($_POST['nombre'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW));
    $email      = strtolower(trim(filter_var($_POST['email'] ?? '', FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW)));
    $password   = (string)($_POST['password'] ?? '');
    $confirm    = (string)($_POST['confirm_password'] ?? '');
    $cargarDemo = isset($_POST['cargar_demo']);

    // Longitudes máximas para evitar buffer overflow y DoS de hashing en Bcrypt
    $nombreLen = mb_strlen($nombre, 'UTF-8');
    $emailLen  = mb_strlen($email, 'UTF-8');
    $passLen   = strlen($password);

    // Batería de validaciones en Backend
    if ($nombreLen < 4 || $nombreLen > 80) {
        $error = 'El nombre completo debe tener entre 4 y 80 caracteres.';
    } elseif (!preg_match('/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s\.\-]{4,80}$/u', $nombre)) {
        $error = 'El nombre solo puede contener letras, espacios, acentos y puntos.';
    } elseif ($emailLen < 5 || $emailLen > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Debe ingresar un correo institucional válido (ej. operador@salud.gob.ve).';
    } elseif ($passLen < 8) {
        $error = 'La contraseña debe contener al menos 8 caracteres.';
    } elseif ($passLen > 72) {
        $error = 'La contraseña no puede exceder 72 caracteres por límites de seguridad criptográfica.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = 'La contraseña debe contener al menos una letra mayúscula (A-Z).';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $error = 'La contraseña debe contener al menos una letra minúscula (a-z).';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = 'La contraseña debe contener al menos un número (0-9).';
    } elseif (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
        $error = 'La contraseña debe contener al menos un símbolo especial (!@#$%...).';
    } elseif ($password !== $confirm) {
        $error = 'La confirmación de la contraseña no coincide con la original.';
    } else {
        // Ejecución Transaccional Atómica con Auditoría Forense
        $pdo->beginTransaction();
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO usuarios (nombre, email, password_hash, rol_id, centro_salud_id, activo)
                VALUES (:nombre, :email, :pass, 1, NULL, 1)
            ");
            $stmt->execute([
                ':nombre' => $nombre,
                ':email'  => $email,
                ':pass'   => $hash
            ]);
            $nuevoAdminId = (int)$pdo->lastInsertId();

            if ($cargarDemo) {
                // 1. Aprovisionar Ambulatorios Iniciales en Mérida si aún no existen
                $stmtCentros = $pdo->query("SELECT COUNT(*) FROM centros_salud");
                if ((int)$stmtCentros->fetchColumn() === 0) {
                    $pdo->exec("
                        INSERT INTO centros_salud (id, codigo_sicm, nombre, tipo_centro_id, medico_director, horario_atencion, direccion, telefono_principal, activo) VALUES
                        (1, 'AMB-MER-001', 'Ambulatorio Urbano III El Llano', 3, 'Dr. Carlos Mendoza (MPPS 45.120)', '24 Horas / Emergencia Continua', 'Av. 3 Independencia, Sector El Llano, Mérida', '0274-2521100', 1),
                        (2, 'AMB-MER-002', 'Ambulatorio Urbano II Santa Rosa', 2, 'Dra. Elena Rivas (MPPS 52.880)', 'Lunes a Viernes 7:00 AM - 7:00 PM', 'Sector La Hechicera / Santa Rosa, Mérida', '0274-2443210', 1),
                        (3, 'AMB-MER-003', 'Ambulatorio Urbano III Belén', 3, 'Dr. Roberto Briceño (MPPS 39.410)', '24 Horas / Guardias Rotativas', 'Calle 16 entre Av. 7 y 8, Sector Belén, Mérida', '0274-2510988', 1)
                        ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);
                    ");

                    $pdo->exec("
                        INSERT INTO centro_recursos (centro_salud_id, recurso_id, operativo, detalle_capacidad) VALUES
                        (1, 1, 1, 'Refrigerador médico Haier Biomedical 350L'),
                        (1, 2, 1, 'Generador Cummins 30 kVA Diésel con ATS'),
                        (1, 3, 1, 'Unidad de Rescate Soporte Avanzado Toyota'),
                        (2, 1, 1, 'Refrigerador vertical 180L con monitoreo digital'),
                        (2, 5, 1, 'Dispensación ambulatoria continua'),
                        (3, 1, 1, 'Nevera horizontal grado farmacéutico 300L'),
                        (3, 2, 1, 'Generador auxiliar 25 kVA'),
                        (3, 4, 1, 'Autoclave digital a vapor 50L')
                        ON DUPLICATE KEY UPDATE operativo = VALUES(operativo);
                    ");
                }

                // 2. Operador Farmaceuta de Demostración para evaluación docente de RBAC
                $hashFarm = password_hash('farmacia123', PASSWORD_DEFAULT);
                $stmtFarm = $pdo->prepare("
                    INSERT INTO usuarios (nombre, email, password_hash, rol_id, centro_salud_id, activo)
                    VALUES (:nombre, :email, :pass, 2, 1, 1)
                    ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)
                ");
                $stmtFarm->execute([
                    ':nombre' => 'Lcda. Elena Farmaceuta',
                    ':email'  => 'farmacia.llano@salud.gob.ve',
                    ':pass'   => $hashFarm
                ]);
            }

            registrarAuditoria($pdo, $nuevoAdminId, 'INICIALIZAR_SISTEMA', 'usuarios', $nuevoAdminId, [
                'evento'       => 'Creación del Administrador Central mediante Asistente de Primer Arranque',
                'nombre'       => $nombre,
                'email'        => $email,
                'rol_id'       => 1,
                'rol_nombre'   => 'rol_admin',
                'datos_prueba' => $cargarDemo ? 'Red asistencial y operador farmacéutico auxiliar cargados' : 'Instalación limpia'
            ]);

            $pdo->commit();

            // Autenticar automáticamente al nuevo Administrador
            autenticarUsuario($pdo, $email, $password);

            $msgExtra = $cargarDemo ? " Red de ambulatorios y farmaceuta sectorial aprovisionados." : "";
            header('Location: index.php?msg=' . urlencode("Bienvenido. El sistema ha sido inicializado exitosamente con la cuenta de {$nombre}.{$msgExtra}"));
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Error de base de datos durante la inicialización: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicialización | Sistema Integrado de Control de Medicamentos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-page: #0f172a;
            --border-subtle: #1e293b;
            --border-input: #334155;
            --input-bg: #1e293b;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-label: #cbd5e1;
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --success: #22c55e;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .setup-container {
            max-width: 440px;
            width: 100%;
        }

        .auth-title {
            font-size: 1.15rem;
            font-weight: 600;
            letter-spacing: -0.01em;
            color: var(--text-main);
            margin-bottom: 0.25rem;
            line-height: 1.35;
        }

        .auth-subtitle {
            font-size: 0.825rem;
            color: var(--text-muted);
            margin-bottom: 1.75rem;
        }

        .form-label {
            font-size: 0.8125rem;
            font-weight: 500;
            color: var(--text-label);
            margin-bottom: 0.35rem;
        }

        .form-control {
            background-color: var(--input-bg);
            border: 1px solid var(--border-input);
            color: var(--text-main);
            font-size: 0.875rem;
            padding: 0.55rem 0.75rem;
            border-radius: 6px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control:focus {
            background-color: var(--input-bg);
            border-color: var(--primary);
            color: var(--text-main);
            box-shadow: 0 0 0 1px var(--primary);
        }

        .form-control::placeholder {
            color: #64748b;
        }

        .form-control.is-valid {
            border-color: var(--success) !important;
            box-shadow: none !important;
        }

        .form-control.is-invalid {
            border-color: var(--danger) !important;
            box-shadow: none !important;
        }

        .feedback-msg {
            font-size: 0.73rem;
            margin-top: 0.25rem;
            display: none;
        }

        .feedback-msg.show-invalid {
            display: block;
            color: #fca5a5;
        }

        .feedback-msg.show-valid {
            display: block;
            color: #86efac;
        }

        /* Password Criteria & Strength Bar */
        .strength-container {
            margin-top: 0.4rem;
        }

        .strength-track {
            height: 4px;
            background-color: #334155;
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.25s ease, background-color 0.25s ease;
        }

        .criteria-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.25rem 0.5rem;
            margin-top: 0.4rem;
        }

        .crit-item {
            font-size: 0.71rem;
            color: #64748b;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            transition: color 0.15s ease;
            user-select: none;
        }

        .crit-item.met {
            color: #4ade80;
            font-weight: 500;
        }

        .crit-icon {
            font-size: 0.65rem;
            width: 12px;
            text-align: center;
        }

        .crit-item.met .crit-icon::before {
            content: "●";
        }

        .crit-item:not(.met) .crit-icon::before {
            content: "○";
        }

        /* Toggle Eye Button */
        .btn-toggle-eye {
            background-color: var(--input-bg);
            border: 1px solid var(--border-input);
            border-left: none;
            color: var(--text-muted);
            padding: 0 0.75rem;
            border-top-right-radius: 6px;
            border-bottom-right-radius: 6px;
            cursor: pointer;
            transition: color 0.15s ease;
        }

        .btn-toggle-eye:hover {
            color: var(--text-main);
            background-color: var(--input-bg);
        }

        .btn-submit {
            background-color: var(--primary);
            border: 1px solid var(--primary);
            color: #ffffff;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            width: 100%;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }

        .btn-submit:hover:not(:disabled) {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }

        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .alert-setup {
            font-size: 0.8125rem;
            padding: 0.6rem 0.85rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            background-color: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
    </style>
</head>
<body>

    <div class="setup-container">
        <!-- 1. Encabezado Sobrio y Técnico -->
        <h1 class="auth-title">Sistema Integrado de Control de Medicamentos</h1>
        <div class="auth-subtitle">Red Municipal de Ambulatorios &bull; Configuración Inicial</div>

        <!-- Alerta de Error de Servidor -->
        <?php if ($error): ?>
            <div class="alert-setup" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- 2. Formulario con Validación Reactiva -->
        <form id="form-setup" action="setup.php" method="POST" novalidate autocomplete="off">
            
            <!-- Nombre Completo -->
            <div class="mb-3">
                <label for="nombre" class="form-label">Nombre completo del Administrador</label>
                <input type="text" class="form-control" id="nombre" name="nombre" 
                    placeholder="Ej. Mikael Pizzi" required maxlength="80" autofocus
                    value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
                <div class="feedback-msg" id="nombre-feedback">
                    Debe tener al menos 4 caracteres (solo letras y espacios).
                </div>
            </div>

            <!-- Correo Institucional -->
            <div class="mb-3">
                <label for="email" class="form-label">Correo institucional</label>
                <input type="email" class="form-control" id="email" name="email" 
                    placeholder="operador@salud.gob.ve" required maxlength="120"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                <div class="feedback-msg" id="email-feedback">
                    Debe ingresar un formato de correo válido (ej. usuario@dominio.com).
                </div>
            </div>

            <!-- Contraseña Principal -->
            <div class="mb-3">
                <label for="password" class="form-label">Contraseña de acceso</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" 
                        placeholder="••••••••••••" required maxlength="72"
                        style="border-top-right-radius: 0; border-bottom-right-radius: 0;">
                    <button class="btn btn-toggle-eye" type="button" id="btn-toggle-pass" title="Mostrar contraseña" tabindex="-1">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                        </svg>
                    </button>
                </div>

                <!-- Barra de Seguridad y Checklist Dinámico -->
                <div class="strength-container">
                    <div class="strength-track">
                        <div class="strength-bar" id="strength-bar"></div>
                    </div>
                    <div class="criteria-list">
                        <div class="crit-item" id="crit-len"><span class="crit-icon"></span> 8+ caracteres</div>
                        <div class="crit-item" id="crit-upper"><span class="crit-icon"></span> 1 mayúscula (A-Z)</div>
                        <div class="crit-item" id="crit-lower"><span class="crit-icon"></span> 1 minúscula (a-z)</div>
                        <div class="crit-item" id="crit-num"><span class="crit-icon"></span> 1 número (0-9)</div>
                        <div class="crit-item" id="crit-special" style="grid-column: span 2;"><span class="crit-icon"></span> 1 símbolo especial (!@#$%...)</div>
                    </div>
                </div>
            </div>

            <!-- Confirmación de Contraseña -->
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirmar contraseña</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                        placeholder="••••••••••••" required maxlength="72"
                        style="border-top-right-radius: 0; border-bottom-right-radius: 0;">
                    <button class="btn btn-toggle-eye" type="button" id="btn-toggle-confirm" title="Mostrar contraseña" tabindex="-1">
                        <svg class="eye-icon" xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                        </svg>
                    </button>
                </div>
                <div class="feedback-msg" id="confirm-feedback">
                    Las contraseñas no coinciden.
                </div>
            </div>

            <!-- Opción de Carga de Datos de Demostración (Quick Start) -->
            <div class="mb-4 p-3 rounded" style="background-color: rgba(30, 41, 59, 0.6); border: 1px solid var(--border-subtle);">
                <div class="form-check m-0">
                    <input class="form-check-input" type="checkbox" name="cargar_demo" id="cargar_demo" checked>
                    <label class="form-check-label text-start" for="cargar_demo">
                        <span class="d-block fw-semibold text-light" style="font-size: 0.8125rem;">Cargar datos de prueba de la red asistencial</span>
                        <span class="d-block text-muted mt-1" style="font-size: 0.72rem; line-height: 1.35;">
                            Puebla 3 ambulatorios iniciales en Mérida y la cuenta de farmaceuta (<span class="font-monospace text-info">farmacia.llano@salud.gob.ve</span> / <span class="font-monospace text-info">farmacia123</span>) para verificar el control de acceso (RBAC).
                        </span>
                    </label>
                </div>
            </div>

            <!-- Botón de Envío -->
            <button type="submit" class="btn btn-submit" id="btn-submit">
                Crear cuenta principal
            </button>
        </form>
    </div>

    <!-- Script de Validación en Tiempo Real (Vanilla JS) -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const form           = document.getElementById('form-setup');
            const nombreInput    = document.getElementById('nombre');
            const emailInput     = document.getElementById('email');
            const passInput      = document.getElementById('password');
            const confirmInput   = document.getElementById('confirm_password');
            const submitBtn      = document.getElementById('btn-submit');

            const nombreFeedback = document.getElementById('nombre-feedback');
            const emailFeedback  = document.getElementById('email-feedback');
            const confirmFeedback= document.getElementById('confirm-feedback');

            const strengthBar    = document.getElementById('strength-bar');
            const critLen        = document.getElementById('crit-len');
            const critUpper      = document.getElementById('crit-upper');
            const critLower      = document.getElementById('crit-lower');
            const critNum        = document.getElementById('crit-num');
            const critSpecial    = document.getElementById('crit-special');

            const btnTogglePass  = document.getElementById('btn-toggle-pass');
            const btnToggleConf  = document.getElementById('btn-toggle-confirm');

            // 1. Alternador de Visibilidad de Contraseñas
            const bindToggle = (btn, input) => {
                btn.addEventListener('click', () => {
                    const isPass = input.type === 'password';
                    input.type = isPass ? 'text' : 'password';
                });
            };
            bindToggle(btnTogglePass, passInput);
            bindToggle(btnToggleConf, confirmInput);

            // 2. Validación de Nombre en Tiempo Real
            const regexNombre = /^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s\.\-]{4,80}$/;
            const validarNombre = () => {
                const val = nombreInput.value.trim();
                if (regexNombre.test(val)) {
                    nombreInput.classList.remove('is-invalid');
                    nombreInput.classList.add('is-valid');
                    nombreFeedback.className = 'feedback-msg';
                    return true;
                } else {
                    nombreInput.classList.remove('is-valid');
                    if (val.length > 0) {
                        nombreInput.classList.add('is-invalid');
                        nombreFeedback.className = 'feedback-msg show-invalid';
                    } else {
                        nombreInput.classList.remove('is-invalid');
                        nombreFeedback.className = 'feedback-msg';
                    }
                    return false;
                }
            };
            nombreInput.addEventListener('input', validarNombre);
            nombreInput.addEventListener('blur', () => {
                if (nombreInput.value.trim().length === 0) {
                    nombreInput.classList.add('is-invalid');
                    nombreFeedback.className = 'feedback-msg show-invalid';
                }
            });

            // 3. Validación de Correo en Tiempo Real
            const regexEmail = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            const validarEmail = () => {
                const val = emailInput.value.trim();
                if (regexEmail.test(val) && val.length <= 120) {
                    emailInput.classList.remove('is-invalid');
                    emailInput.classList.add('is-valid');
                    emailFeedback.className = 'feedback-msg';
                    return true;
                } else {
                    emailInput.classList.remove('is-valid');
                    if (val.length > 0) {
                        emailInput.classList.add('is-invalid');
                        emailFeedback.className = 'feedback-msg show-invalid';
                    } else {
                        emailInput.classList.remove('is-invalid');
                        emailFeedback.className = 'feedback-msg';
                    }
                    return false;
                }
            };
            emailInput.addEventListener('input', validarEmail);
            emailInput.addEventListener('blur', () => {
                if (!regexEmail.test(emailInput.value.trim())) {
                    emailInput.classList.add('is-invalid');
                    emailFeedback.className = 'feedback-msg show-invalid';
                }
            });

            // 4. Validación Reactiva de Complejidad de Contraseña
            const regexUpper   = /[A-Z]/;
            const regexLower   = /[a-z]/;
            const regexNum     = /[0-9]/;
            const regexSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/;

            const validarPassword = () => {
                const pass = passInput.value;
                const hasLen     = pass.length >= 8 && pass.length <= 72;
                const hasUpper   = regexUpper.test(pass);
                const hasLower   = regexLower.test(pass);
                const hasNum     = regexNum.test(pass);
                const hasSpecial = regexSpecial.test(pass);

                // Actualizar Checklist dinámico
                critLen.classList.toggle('met', hasLen);
                critUpper.classList.toggle('met', hasUpper);
                critLower.classList.toggle('met', hasLower);
                critNum.classList.toggle('met', hasNum);
                critSpecial.classList.toggle('met', hasSpecial);

                // Calcular puntaje (0 a 5)
                const score = [hasLen, hasUpper, hasLower, hasNum, hasSpecial].filter(Boolean).length;
                const percentage = (score / 5) * 100;
                strengthBar.style.width = percentage + '%';

                if (score <= 2) {
                    strengthBar.style.backgroundColor = 'var(--danger)';
                } else if (score <= 4) {
                    strengthBar.style.backgroundColor = 'var(--warning)';
                } else {
                    strengthBar.style.backgroundColor = 'var(--success)';
                }

                const isValid = score === 5;
                if (pass.length > 0) {
                    passInput.classList.toggle('is-valid', isValid);
                    passInput.classList.toggle('is-invalid', !isValid);
                } else {
                    passInput.classList.remove('is-valid', 'is-invalid');
                }

                validarConfirm();
                return isValid;
            };
            passInput.addEventListener('input', validarPassword);

            // 5. Validación de Confirmación
            const validarConfirm = () => {
                const pass = passInput.value;
                const conf = confirmInput.value;

                if (conf.length === 0) {
                    confirmInput.classList.remove('is-valid', 'is-invalid');
                    confirmFeedback.className = 'feedback-msg';
                    return false;
                }

                if (pass === conf && pass.length >= 8) {
                    confirmInput.classList.remove('is-invalid');
                    confirmInput.classList.add('is-valid');
                    confirmFeedback.textContent = 'Las contraseñas coinciden.';
                    confirmFeedback.className = 'feedback-msg show-valid';
                    return true;
                } else {
                    confirmInput.classList.remove('is-valid');
                    confirmInput.classList.add('is-invalid');
                    confirmFeedback.textContent = 'Las contraseñas no coinciden.';
                    confirmFeedback.className = 'feedback-msg show-invalid';
                    return false;
                }
            };
            confirmInput.addEventListener('input', validarConfirm);

            // 6. Control de Envío (Submit Guard)
            form.addEventListener('submit', (e) => {
                const nOk = validarNombre();
                const eOk = validarEmail();
                const pOk = validarPassword();
                const cOk = validarConfirm();

                if (!nOk || !eOk || !pOk || !cOk) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Forzar mensajes de error en los campos incompletos
                    if (!nOk) {
                        nombreInput.classList.add('is-invalid');
                        nombreFeedback.className = 'feedback-msg show-invalid';
                        nombreInput.focus();
                    } else if (!eOk) {
                        emailInput.classList.add('is-invalid');
                        emailFeedback.className = 'feedback-msg show-invalid';
                        emailInput.focus();
                    } else if (!pOk) {
                        passInput.classList.add('is-invalid');
                        passInput.focus();
                    } else if (!cOk) {
                        confirmInput.classList.add('is-invalid');
                        confirmFeedback.className = 'feedback-msg show-invalid';
                        confirmInput.focus();
                    }
                } else {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Inicializando sistema...';
                }
            });
        });
    </script>
</body>
</html>
