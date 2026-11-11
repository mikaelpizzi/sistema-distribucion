<?php
// src/404.php
// SICM | Sistema Integrado de Control de Medicamentos - Página de Recurso No Encontrado (404)
declare(strict_types=1);

http_response_code(404);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/configuracion.php';

$configInst = [
    'nombre_sistema' => 'Sistema Integrado de Control de Medicamentos',
    'siglas_sistema' => 'SICM',
    'ente_rector'    => 'Dirección Municipal de Salud',
    'logotipo_ruta'  => null,
];

try {
    $pdo = getDB();
    $configInst = obtenerConfiguracionInstitucional($pdo);
} catch (Throwable $e) {
    // Modo resiliente en caso de indisponibilidad temporal de la base de datos
}

$faviconRuta = (!empty($configInst['logotipo_ruta']) && file_exists(__DIR__ . '/' . $configInst['logotipo_ruta']))
    ? $configInst['logotipo_ruta']
    : 'public/img/favicon.svg';
$faviconMime = str_ends_with(strtolower($faviconRuta), '.svg') ? 'image/svg+xml' : 'image/png';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Recurso No Encontrado | <?= htmlspecialchars($configInst['siglas_sistema']) ?></title>
    <link rel="icon" type="<?= $faviconMime ?>" href="<?= htmlspecialchars($faviconRuta) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="public/css/app.css?v=<?= filemtime(__DIR__ . '/public/css/app.css') ?>" rel="stylesheet">
</head>

<body class="error-layout">

    <div class="error-card">
        <!-- Identidad Institucional -->
        <div class="d-flex align-items-center justify-content-center gap-2 mb-4">
            <?php if (!empty($configInst['logotipo_ruta']) && file_exists(__DIR__ . '/' . $configInst['logotipo_ruta'])): ?>
                <img src="<?= htmlspecialchars($configInst['logotipo_ruta']) ?>" alt="Logo Institucional" style="max-height: 42px; object-fit: contain;">
            <?php else: ?>
                <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25" style="width: 36px; height: 36px;">
                    <i class="bi bi-shield-plus fs-5 text-primary"></i>
                </div>
            <?php endif; ?>
            <div class="text-start">
                <div class="fw-bold text-dark lh-1" style="font-size: 0.95rem;"><?= htmlspecialchars($configInst['siglas_sistema']) ?></div>
                <div class="text-muted small" style="font-size: 0.72rem;"><?= htmlspecialchars($configInst['ente_rector']) ?></div>
            </div>
        </div>

        <!-- Ícono e Identificador 404 -->
        <div class="icon-badge">
            <i class="bi bi-signpost-split fs-1"></i>
        </div>
        
        <div>
            <span class="badge bg-danger-subtle text-danger border border-danger-subtle code-badge mb-2">ERROR HTTP 404</span>
        </div>

        <h4 class="fw-bold text-dark mb-2">Recurso o Dirección No Localizada</h4>
        
        <p class="text-muted small mb-3" style="line-height: 1.6;">
            El archivo o dirección solicitada (<code class="text-danger fw-semibold"><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?></code>) no existe en el servidor del sistema de salud.
        </p>

        <p class="text-secondary small mb-4" style="font-size: 0.8rem;">
            Verifique la dirección ingresada o regrese a la consola operativa principal mediante el botón a continuación.
        </p>

        <!-- Botón de Retorno -->
        <div class="d-flex justify-content-center">
            <a href="index.php" class="btn btn-primary btn-sm px-4 py-2 fw-semibold d-inline-flex align-items-center gap-2">
                <i class="bi bi-house-door-fill"></i> Volver al Panel Principal
            </a>
        </div>
    </div>

    <div class="mt-4 text-center text-muted small" style="font-size: 0.75rem;">
        <?= htmlspecialchars($configInst['nombre_sistema']) ?> &bull; <?= date('Y') ?>
    </div>

</body>
</html>
