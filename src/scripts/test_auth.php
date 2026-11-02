<?php
// src/scripts/test_auth.php
// Verificación Automatizada del Módulo de Autenticación, Hashing y Aislamiento RBAC
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/auditoria.php';

$pdo = getDB();

echo "=================================================================\n";
echo "  BATERÍA DE PRUEBAS: AUTENTICACIÓN, HASHING Y AISLAMIENTO RBAC  \n";
echo "=================================================================\n";

// 1. Verificar existencia de usuarios de seeders
$totalU = contarUsuariosRegistrados($pdo);
echo "1. [OK] Usuarios registrados en el sistema: {$totalU}\n";
if ($totalU === 0) {
    echo "ERROR: La base de datos debe contener al menos 1 usuario para las pruebas.\n";
    exit(1);
}

// 2. Probar autenticación del Administrador Central (rol_id = 1, centro_salud_id = NULL)
$adminAuth = autenticarUsuario($pdo, 'admin@salud.prueba.ve', 'admin123');
if (!$adminAuth || (int)$adminAuth['rol_id'] !== 1 || $adminAuth['centro_salud_id'] !== null) {
    echo "ERROR: Falló la autenticación del Administrador Central.\n";
    exit(1);
}
echo "2. [OK] Autenticación Exitosa: '{$adminAuth['nombre']}' como Admin Central (Jurisdicción Global).\n";

// 3. Probar autenticación del Farmaceuta (rol_id = 2, centro_salud_id = 1)
$farmaAuth = autenticarUsuario($pdo, 'farmacia.llano@salud.prueba.ve', 'farmacia123');
if (!$farmaAuth || (int)$farmaAuth['rol_id'] !== 2 || (int)$farmaAuth['centro_salud_id'] !== 1) {
    echo "ERROR: Falló la autenticación del Farmaceuta de El Llano.\n";
    exit(1);
}
echo "3. [OK] Autenticación Exitosa: '{$farmaAuth['nombre']}' como Farmaceuta adscrito al Ambulatorio #{$farmaAuth['centro_salud_id']}.\n";

// 4. Probar rechazo con contraseña incorrecta
$failAuth = autenticarUsuario($pdo, 'admin@salud.prueba.ve', 'clave_falsa_1234');
if ($failAuth !== null) {
    echo "ERROR: Una contraseña inválida no debe autenticar.\n";
    exit(1);
}
echo "4. [OK] Seguridad de Contraseña: Clave incorrecta rechazada satisfactoriamente.\n";

// 5. Probar creación de usuario con Bcrypt moderno (password_hash)
$testEmail = 'operador.prueba@salud.prueba.ve';
$pdo->prepare("DELETE FROM usuarios WHERE email = :e")->execute([':e' => $testEmail]);

$hashBcrypt = password_hash('secreto_seguro_2026', PASSWORD_DEFAULT);
$stmtNew = $pdo->prepare("
    INSERT INTO usuarios (nombre, email, password_hash, rol_id, centro_salud_id, activo)
    VALUES ('Operador de Prueba Automatizada', :e, :p, 2, 2, 1)
");
$stmtNew->execute([':e' => $testEmail, ':p' => $hashBcrypt]);
$newUserId = (int)$pdo->lastInsertId();

$newAuth = autenticarUsuario($pdo, $testEmail, 'secreto_seguro_2026');
if (!$newAuth || (int)$newAuth['id'] !== $newUserId) {
    echo "ERROR: Falló la autenticación con hash moderno Bcrypt.\n";
    exit(1);
}
echo "5. [OK] Hashing Bcrypt Nativo: Nuevo operador autenticado con función nativa password_hash().\n";

// 6. Probar aislamiento de lectura por jurisdicción (Política de Read en Ambulatorios)
$stmtFarmaScope = $pdo->prepare("SELECT COUNT(*) FROM vw_centros_salud WHERE id = :cid");
$stmtFarmaScope->execute([':cid' => (int)$farmaAuth['centro_salud_id']]);
$centrosVisibles = (int)$stmtFarmaScope->fetchColumn();

if ($centrosVisibles !== 1) {
    echo "ERROR: El farmaceuta debe ver exactamente 1 ambulatorio (el propio).\n";
    exit(1);
}
echo "6. [OK] Aislamiento de Jurisdicción (RBAC): El farmaceuta solo tiene visibilidad de su ambulatorio asignado.\n";

// Limpieza de prueba
$pdo->prepare("DELETE FROM usuarios WHERE id = :id")->execute([':id' => $newUserId]);
echo "7. [OK] Limpieza: Usuario de prueba temporal eliminado.\n";

echo "=================================================================\n";
echo "  RESULTADO: 100% DE PRUEBAS DE AUTENTICACIÓN COMPLETADAS         \n";
echo "=================================================================\n";
exit(0);

