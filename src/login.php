<?php
// src/login.php
// Sistema Integrado de Control de Medicamentos | Acceso de Operadores
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';
require_once __DIR__ . '/helpers/configuracion.php';

$pdo = getDB();
$configInst = obtenerConfiguracionInstitucional($pdo);


// 1. Si no hay usuarios en la base de datos y no se pidió seed, ir a setup
if (contarUsuariosRegistrados($pdo) === 0) {
    header('Location: setup.php');
    exit;
}

// 2. Si ya está autenticado, enviar directamente al entorno de trabajo
if (obtenerUsuarioSesion() !== null) {
    header('Location: index.php');
    exit;
}

$error = $_GET['error'] ?? null;
$msg   = $_GET['msg'] ?? null;

// 3. Procesar Inicio de Sesión
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Por favor ingrese su correo institucional y su contraseña.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingrese un formato de correo electrónico válido.';
    } else {
        $resultado = autenticarUsuario($pdo, $email, $password);

        if ($resultado && !isset($resultado['error'])) {
            registrarAuditoria($pdo, (int)$resultado['id'], 'INICIO_SESION', 'usuarios', (int)$resultado['id'], [
                'evento'          => 'Inicio de sesión exitoso',
                'rol'             => $resultado['rol_nombre'],
                'centro_salud_id' => $resultado['centro_salud_id'],
                'email'           => $resultado['email']
            ]);

            header('Location: index.php');
            exit;
        } elseif (isset($resultado['error']) && $resultado['error'] === 'cuenta_inactiva') {
            registrarAuditoria($pdo, null, 'INTENTO_LOGIN_RECHAZADO', 'usuarios', null, [
                'motivo'          => 'Cuenta desactivada',
                'email_ingresado' => $email
            ]);
            $error = 'Esta cuenta ha sido desactivada. Contacte al administrador.';
        } elseif (isset($resultado['error']) && $resultado['error'] === 'usuario_no_encontrado') {
            registrarAuditoria($pdo, null, 'INTENTO_LOGIN_FALLIDO', 'usuarios', null, [
                'motivo'          => 'Usuario no encontrado',
                'email_ingresado' => $email
            ]);
            $error = 'Credenciales inválidas. Verifique su correo y contraseña.';
        } elseif (isset($resultado['error']) && $resultado['error'] === 'password_incorrecto') {
            registrarAuditoria($pdo, null, 'INTENTO_LOGIN_FALLIDO', 'usuarios', null, [
                'motivo'          => 'Contraseña incorrecta',
                'email_ingresado' => $email
            ]);
            $error = 'Credenciales inválidas. Verifique su correo y contraseña.';
        } else {
            registrarAuditoria($pdo, null, 'INTENTO_LOGIN_FALLIDO', 'usuarios', null, [
                'evento'          => 'Intento de acceso rechazado',
                'email_ingresado' => $email
            ]);
            $error = 'Credenciales inválidas. Verifique su correo y contraseña.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($configInst['siglas_sistema']) ?> - <?= htmlspecialchars($configInst['nombre_sistema']) ?></title>
    <link rel="icon" type="<?= !empty($configInst['logotipo_ruta']) && file_exists(__DIR__ . '/' . $configInst['logotipo_ruta']) ? (str_ends_with(strtolower($configInst['logotipo_ruta']), '.svg') ? 'image/svg+xml' : 'image/png') : 'image/svg+xml' ?>" href="<?= !empty($configInst['logotipo_ruta']) && file_exists(__DIR__ . '/' . $configInst['logotipo_ruta']) ? htmlspecialchars($configInst['logotipo_ruta']) : 'public/img/favicon.svg' ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-page: #f8fafc;
            --card-bg: #ffffff;
            --border-card: #e2e8f0;
            --border-input: #cbd5e1;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --text-label: #334155;
            --primary: #0d6efd;
            --primary-hover: #0b5ed7;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-main);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            margin: 0;
            -webkit-font-smoothing: antialiased;
        }

        .auth-card {
            max-width: 410px;
            width: 100%;
            background-color: var(--card-bg);
            border: 1px solid var(--border-card);
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 2.25rem 2rem;
        }

        .auth-title {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: var(--text-main);
            line-height: 1.3;
        }

        .auth-subtitle {
            font-size: 0.8125rem;
            color: var(--text-muted);
            margin-bottom: 1.75rem;
        }

        .form-label {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-label);
            margin-bottom: 0.35rem;
        }

        .form-control {
            background-color: #ffffff;
            border: 1px solid var(--border-input);
            color: var(--text-main);
            font-size: 0.875rem;
            padding: 0.55rem 0.75rem;
            border-radius: 6px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control:focus {
            background-color: #ffffff;
            border-color: #3b82f6;
            color: var(--text-main);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        .btn-toggle-eye {
            background-color: #ffffff;
            border: 1px solid var(--border-input);
            border-left: none;
            color: var(--text-muted);
            padding: 0 0.75rem;
            border-top-right-radius: 6px;
            border-bottom-right-radius: 6px;
            cursor: pointer;
            transition: color 0.15s ease, background-color 0.15s ease;
        }

        .btn-toggle-eye:hover {
            color: var(--text-main);
            background-color: #f8fafc;
        }

        .btn-submit {
            background-color: var(--primary);
            border: 1px solid var(--primary);
            color: #ffffff;
            font-size: 0.875rem;
            font-weight: 600;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            width: 100%;
            transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
            border-color: #0a58ca;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(13, 110, 253, 0.25);
        }
    </style>
</head>
<body>

    <div class="auth-card">
        <!-- Identidad Institucional Parametrizable (White-labeling) -->
        <div class="text-center mb-3">
            <?php if (!empty($configInst['logotipo_ruta'])): ?>
                <img src="<?= htmlspecialchars($configInst['logotipo_ruta']) ?>" alt="Logotipo Institucional" style="max-height: 52px; max-width: 100%; object-fit: contain;" class="mb-2">
            <?php else: ?>
                <div class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary bg-opacity-10 text-primary border border-primary border-opacity-20 p-2 mb-2" style="width: 50px; height: 50px;">
                    <i class="bi bi-shield-plus fs-2"></i>
                </div>
            <?php endif; ?>
        </div>

        <div class="d-flex align-items-center justify-content-between mb-1">
            <h1 class="auth-title mb-0"><?= htmlspecialchars($configInst['nombre_sistema']) ?></h1>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace ms-2"><?= htmlspecialchars($configInst['siglas_sistema']) ?></span>
        </div>
        <div class="auth-subtitle"><?= htmlspecialchars($configInst['ente_rector']) ?> &bull; <?= htmlspecialchars($configInst['municipio']) ?></div>

        <!-- Notificaciones -->
        <?php if ($msg): ?>
            <div class="alert alert-success border-success-subtle py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size: 0.8125rem;">
                <i class="bi bi-check-circle-fill text-success flex-shrink-0"></i>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger border-danger-subtle py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size: 0.8125rem;">
                <i class="bi bi-exclamation-triangle-fill text-danger flex-shrink-0"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Formulario de Acceso Institucional -->
        <form id="form-login" action="login.php" method="POST" novalidate>
            <div class="mb-3">
                <label for="email" class="form-label">Correo institucional</label>
                <input type="email" class="form-control" id="email" name="email" 
                    placeholder="operador@salud.prueba.ve" required autocomplete="email"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" 
                        placeholder="••••••••" required autocomplete="current-password"
                        style="border-top-right-radius: 0; border-bottom-right-radius: 0;">
                    <button class="btn btn-toggle-eye" type="button" id="btn-toggle-pass" title="Mostrar contraseña" aria-label="Mostrar contraseña">
                        <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
                            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-submit">
                Iniciar sesión
            </button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const passInput = document.getElementById('password');
            const btnToggle = document.getElementById('btn-toggle-pass');

            // Alternar visibilidad de contraseña
            if (btnToggle && passInput) {
                btnToggle.addEventListener('click', () => {
                    const isPass = passInput.getAttribute('type') === 'password';
                    passInput.setAttribute('type', isPass ? 'text' : 'password');
                });
            }
        });
    </script>
</body>
</html>

