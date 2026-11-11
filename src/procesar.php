<?php
// src/procesar.php - Action Router Modular (<100 líneas)
declare(strict_types=1);
define('SICM_CONTROLADOR', true);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/auditoria.php';
require_once __DIR__ . '/helpers/configuracion.php';

$pdo = getDB();
$accion = $_REQUEST['accion'] ?? '';
$usuario = obtenerUsuarioSesion();
if (!$usuario) { header('Location: login.php'); exit; }
$usuarioSesionId = $usuario['id'];
$esAdmin = ((int) $usuario['rol_id'] === 1);

establecerContextoAuditoriaSQL($pdo, $usuarioSesionId, $_SESSION['sesion_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null), $_SESSION['sesion_user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null));

$archivoSubidoRuta = null;
$rutas = [
    'guardar_centro' => 'act_centros.php', 'cambiar_estado_centro' => 'act_centros.php',
    'crear_medicamento' => 'act_medicamentos.php', 'editar_medicamento' => 'act_medicamentos.php', 'guardar_medicamento' => 'act_medicamentos.php', 'cambiar_estado_medicamento' => 'act_medicamentos.php',
    'crear_tipo_centro' => 'act_maestras.php', 'toggle_tipo_centro' => 'act_maestras.php', 'crear_recurso' => 'act_maestras.php', 'toggle_recurso' => 'act_maestras.php',
    'crear_forma_farmaceutica' => 'act_maestras.php', 'guardar_forma' => 'act_maestras.php', 'crear_patologia' => 'act_maestras.php', 'guardar_patologia' => 'act_maestras.php',
    'cambiar_estado_patologia' => 'act_maestras.php', 'crear_rol' => 'act_maestras.php', 'actualizar_rol_permisos' => 'act_maestras.php', 'eliminar_rol' => 'act_maestras.php',
    'guardar_paciente' => 'act_pacientes.php', 'cambiar_estado_paciente' => 'act_pacientes.php',
    'guardar_recipe' => 'act_recipes.php', 'desactivar_recipe' => 'act_recipes.php', 'toggle_estado_recipe' => 'act_recipes.php',
    'procesar_despacho_fefo' => 'act_despachos.php', 'guardar_usuario' => 'act_usuarios.php', 'cambiar_estado_usuario' => 'act_usuarios.php',
    'exportar_acta_pericial' => 'act_auditoria.php', 'descargar_respaldo' => 'act_configuracion.php', 'guardar_identidad_institucional' => 'act_configuracion.php',
];

$permisos = [
    'guardar_centro' => !empty($_POST['id']) ? 'CENTROS_EDITAR' : 'CENTROS_CREAR', 'cambiar_estado_centro' => 'CENTROS_ESTADO',
    'guardar_usuario' => 'USR_GESTIONAR', 'cambiar_estado_usuario' => 'USR_GESTIONAR', 'descargar_respaldo' => 'SISTEMA_RESPALDO',
    'exportar_acta_pericial' => 'AUDIT_EXPORTAR', 'guardar_identidad_institucional' => 'CONFIG_SISTEMA',
    'crear_medicamento' => 'MED_CREAR', 'editar_medicamento' => 'MED_EDITAR', 'guardar_medicamento' => 'MED_EDITAR', 'cambiar_estado_medicamento' => 'MED_EDITAR',
    'guardar_paciente' => 'PACIENTES_GESTIONAR', 'guardar_recipe' => 'RECIPES_GESTIONAR', 'desactivar_recipe' => 'RECIPES_GESTIONAR',
    'toggle_estado_recipe' => 'RECIPES_GESTIONAR', 'procesar_despacho_fefo' => 'DESP_PROCESAR',
];

$tabDestino = match (true) {
    str_contains($accion, 'tipo') || str_contains($accion, 'recurso') || str_contains($accion, 'rol') => 'maestras',
    str_contains($accion, 'usuario') => 'usuarios', str_contains($accion, 'paciente') => 'pacientes',
    str_contains($accion, 'recipe') => 'recipes', str_contains($accion, 'configuracion') || str_contains($accion, 'identidad') => 'configuracion',
    str_contains($accion, 'patologia') => 'medicamentos&subtab=patologias', str_contains($accion, 'forma') => 'medicamentos&subtab=formas',
    str_contains($accion, 'medicamento') => 'medicamentos&subtab=vademecum', str_contains($accion, 'despacho') => 'despachos',
    default => 'centros',
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validarCsrfToken($_POST['csrf_token'] ?? '')) {
    $_SESSION['mensaje_error'] = 'Sesión de formulario expirada o inválida. Intente de nuevo.';
    header("Location: index.php?tab={$tabDestino}&error=" . urlencode($_SESSION['mensaje_error']));
    exit;
}

$permisoReq = $permisos[$accion] ?? (isset($rutas[$accion]) ? 'MAESTRAS_GESTIONAR' : null);
if ($permisoReq && !tienePermiso($permisoReq)) {
    registrarAuditoria($pdo, $usuarioSesionId, 'ACCESO_DENEGADO', 'seguridad', null, ['accion_solicitada' => $accion, 'permiso_requerido' => $permisoReq]);
    $_SESSION['mensaje_error'] = "Acceso denegado: Su rol no posee el permiso atómico requerido [{$permisoReq}].";
    header('Location: index.php?error=' . urlencode($_SESSION['mensaje_error']));
    exit;
}

if (!isset($rutas[$accion]) || !file_exists(__DIR__ . '/actions/' . $rutas[$accion])) {
    header('Location: index.php');
    exit;
}

try {
    require __DIR__ . '/actions/' . $rutas[$accion];
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (!empty($archivoSubidoRuta) && file_exists($archivoSubidoRuta)) { @unlink($archivoSubidoRuta); }
    $code = (int) ($e->errorInfo[1] ?? 0);
    $msg = match (true) {
        $code === 1062 && str_contains($e->getMessage(), 'uk_retiro_mensual') => 'El paciente ya retiró su dotación para este tratamiento en el periodo actual.',
        $code === 1062 && str_contains($e->getMessage(), 'email') => 'El correo electrónico ya se encuentra registrado por otro usuario.',
        $code === 1062 => 'Ya existe un registro con los datos ingresados.',
        $code === 1451 => 'No es posible modificar este registro porque tiene datos vinculados.',
        $code === 1452 => 'El registro hace referencia a un elemento que no existe.',
        $e->getCode() === '45000' || $code === 1644 => !empty($e->errorInfo[2]) ? $e->errorInfo[2] : 'Operación rechazada por reglas de auditoría.',
        default => 'Ocurrió un error al procesar la solicitud en la base de datos.'
    };
    $_SESSION['mensaje_error'] = $msg;
    $extra = ($tabDestino === 'despachos' && !empty($_POST['q_paciente_retorno'])) ? '&q_paciente=' . urlencode($_POST['q_paciente_retorno']) : '';
    header("Location: index.php?tab={$tabDestino}{$extra}&error=" . urlencode($msg));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (!empty($archivoSubidoRuta) && file_exists($archivoSubidoRuta)) { @unlink($archivoSubidoRuta); }
    header('Location: index.php?tab=' . $tabDestino . '&error=' . urlencode('Error del sistema: ' . $e->getMessage()));
    exit;
}

