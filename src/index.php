<?php
// src/index.php
// SICM | Sistema Integrado de Control de Medicamentos - Front Controller
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/modules.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';
require_once __DIR__ . '/helpers/configuracion.php';

$pdo = getDB();
$usuario = requerirAutenticacion($pdo);
establecerContextoAuditoriaSQL($pdo, (int) $usuario['id']);
$esAdmin = ((int) $usuario['rol_id'] === 1);
$centroUsuarioId = $usuario['centro_salud_id'];
$puedeVerTodaLaRed = puedeSupervisarTodaLaRed();
$configInst = obtenerConfiguracionInstitucional($pdo);

$tabActiva = $_GET['tab'] ?? 'centros';
$mensajeExito = $_GET['msg'] ?? ($_SESSION['mensaje_exito'] ?? null);
$mensajeError = $_GET['error'] ?? ($_SESSION['mensaje_error'] ?? null);
unset($_SESSION['mensaje_exito'], $_SESSION['mensaje_error']);

// Mapeo de Pestañas a Permisos Atómicos de la Matriz RBAC
$permisosPorTab = [
    'centros'       => 'CENTROS_VER',
    'medicamentos'  => 'MED_VER',
    'pacientes'     => 'PACIENTES_VER',
    'recipes'       => 'RECIPES_VER',
    'despachos'     => 'DESP_VER',
    'auditoria'     => 'AUDIT_VER',
    'usuarios'      => 'USR_VER',
    'maestras'      => 'MAESTRAS_GESTIONAR',
    'configuracion' => 'CONFIG_SISTEMA'
];

$tabsValidas = array_keys(array_filter(obtenerModulosDisponibles()));
$primeraTabAutorizada = null;
foreach ($permisosPorTab as $tKey => $pReq) {
    if (esModuloActivo($tKey) && tienePermiso($pReq)) {
        $primeraTabAutorizada = $tKey;
        break;
    }
}

if ($primeraTabAutorizada === null) {
    die('No posee permisos activos para acceder a ningún módulo del sistema o todos los módulos autorizados se encuentran inactivos.');
}

if (!in_array($tabActiva, $tabsValidas, true) || !esModuloActivo($tabActiva)) {
    if (isset($_GET['tab'])) {
        header("Location: index.php?tab={$primeraTabAutorizada}");
        exit;
    }
    $tabActiva = $primeraTabAutorizada;
} elseif (isset($permisosPorTab[$tabActiva]) && !tienePermiso($permisosPorTab[$tabActiva])) {
    header("Location: index.php?tab={$primeraTabAutorizada}");
    exit;
}

// Carga modular y ejecución de la vista activa con búfer para breadcrumbs reactivos
ob_start();
$tabFile = __DIR__ . "/views/tabs/tab_{$tabActiva}.php";
if (file_exists($tabFile) && esModuloActivo($tabActiva)) {
    require $tabFile;
} else {
    require __DIR__ . '/views/tabs/tab_404.php';
}
$tabContenido = ob_get_clean();

require __DIR__ . '/views/layout/header.php';
echo $tabContenido;
require __DIR__ . '/views/layout/footer.php';
