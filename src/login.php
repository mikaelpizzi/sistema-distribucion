<?php
// src/login.php
// Sistema Integrado de Control de Medicamentos | Acceso de Operadores
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';

$pdo = getDB();


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
                'motivo'          => 'Cuenta desactivada (activo = 0)',
                'email_ingresado' => $email
            ]);
            $error = "Acceso denegado: La cuenta del operador '{$email}' está desactivada en la base de datos (activo = 0).";
        } elseif (isset($resultado['error']) && $resultado['error'] === 'usuario_no_encontrado') {
            registrarAuditoria($pdo, null, 'INTENTO_LOGIN_FALLIDO', 'usuarios', null, [
                'motivo'          => 'Usuario no encontrado',
                'email_ingresado' => $email
            ]);
            $error = "Acceso denegado: El correo '{$email}' no existe en el catálogo de operadores (tabla usuarios).";
        } elseif (isset($resultado['error']) && $resultado['error'] === 'password_incorrecto') {
            registrarAuditoria($pdo, null, 'INTENTO_LOGIN_FALLIDO', 'usuarios', null, [
                'motivo'          => 'Contraseña incorrecta',
                'email_ingresado' => $email
            ]);
            $error = "Acceso denegado: La contraseña ingresada no coincide con el hash del usuario '{$email}'.";
        } else {
            registrarAuditoria($pdo, null, 'INTENTO_LOGIN_FALLIDO', 'usuarios', null, [
                'evento'          => 'Intento de acceso rechazado',
                'email_ingresado' => $email
            ]);
            $error = 'Credenciales no válidas. Verifique su correo institucional y su contraseña.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema Integrado de Control de Medicamentos</title>
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

        .auth-container {
            max-width: 380px;
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
            padding: 0.55rem 1rem;
            border-radius: 6px;
            width: 100%;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            color: #ffffff;
        }

        .auth-divider {
            border-top: 1px solid var(--border-subtle);
            margin-top: 1.5rem;
            padding-top: 1.25rem;
        }

        .form-check-input {
            background-color: var(--input-bg);
            border-color: var(--border-input);
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .form-check-label {
            font-size: 0.8125rem;
            color: var(--text-muted);
            cursor: pointer;
            user-select: none;
        }

        .alert-auth {
            font-size: 0.8125rem;
            padding: 0.6rem 0.85rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            border: 1px solid transparent;
        }
        .alert-auth-danger {
            background-color: rgba(239, 68, 68, 0.12);
            border-color: rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
        .alert-auth-success {
            background-color: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.3);
            color: #86efac;
        }
    </style>
</head>
<body>

    <div class="auth-container">
        <!-- 1. Jerarquía de Títulos Limpia (Sin acrónimos ni badges) -->
        <h1 class="auth-title">Sistema Integrado de Control de Medicamentos</h1>
        <div class="auth-subtitle">Red Municipal de Ambulatorios</div>

        <!-- Notificaciones -->
        <?php if ($msg): ?>
            <div class="alert-auth alert-auth-success">
                <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-auth alert-auth-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- 2. Formulario Sobrio -->
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

