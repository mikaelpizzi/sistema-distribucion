<?php
// src/views/layout/header.php
// SICM | Encabezado Institucional y Apertura del Layout
declare(strict_types=1);

$faviconRuta = (!empty($configInst['logotipo_ruta']) && file_exists(__DIR__ . '/../../' . $configInst['logotipo_ruta']))
    ? $configInst['logotipo_ruta']
    : 'public/img/favicon.svg';
$faviconMime = str_ends_with(strtolower($faviconRuta), '.svg') ? 'image/svg+xml' : 'image/png';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($configInst['siglas_sistema']) ?> - <?= htmlspecialchars($configInst['nombre_sistema']) ?></title>
    <link rel="icon" type="<?= $faviconMime ?>" href="<?= htmlspecialchars($faviconRuta) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="public/css/app.css?v=<?= filemtime(__DIR__ . '/../../public/css/app.css') ?>" rel="stylesheet">
</head>

<body>

    <div class="sicm-layout" id="sicm-layout">
        <!-- BARRA LATERAL INSTITUCIONAL (SIDEBAR) -->
        <?php require __DIR__ . '/sidebar.php'; ?>

        <!-- ÁREA DE CONTENIDO PRINCIPAL -->
        <div class="sicm-main">
            <!-- BARRA SUPERIOR MINIMALISTA Y ALERTAS FLASH -->
            <?php require __DIR__ . '/topbar.php'; ?>

            <!-- CONTENEDOR FLUIDO DE TRABAJO -->
            <main class="container-fluid py-3 px-3 px-md-4">
