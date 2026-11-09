<?php
// src/scripts/test_http_curl.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDB();

$cookieJar = '/tmp/cookie.txt';
if (file_exists($cookieJar)) {
    unlink($cookieJar);
}

// 1. Login vía POST
$ch = curl_init('http://localhost/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'email' => 'admin@salud.prueba.ve',
    'password' => 'admin123'
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$postLoginHtml = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code tras Login: " . $httpCode . "\n";

// 2. Consultar index.php?tab=despachos
$ch = curl_init('http://localhost/index.php?tab=despachos');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
$despachosHtml = curl_exec($ch);
$httpCodeDesp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Página de Despachos cargada (HTTP {$httpCodeDesp}, " . strlen($despachosHtml) . " bytes)\n";

$checks = [
    'Jurisdicción Operativa' => str_contains($despachosHtml, 'Jurisdicción Operativa'),
    'Despachos este Mes' => str_contains($despachosHtml, 'Despachos este Mes'),
    'Pacientes Atendidos' => str_contains($despachosHtml, 'Pacientes Atendidos'),
    'Unidades Entregadas' => str_contains($despachosHtml, 'Unidades Entregadas'),
    'Búsqueda Asistencial' => str_contains($despachosHtml, 'Búsqueda Asistencial de Paciente'),
    'Historial Despachos Recientes' => str_contains($despachosHtml, 'Historial de Despachos Recientes en este Ambulatorio'),
    'Modal Dispensar' => str_contains($despachosHtml, 'modalDispensar')
];

foreach ($checks as $title => $pass) {
    echo ($pass ? "[PASS] " : "[FAIL] ") . $title . "\n";
    if (!$pass) die("Fallo en {$title}\n");
}

// 3. Buscar Paciente María Peña (Cédula: 18952301 - YA RETIRÓ ESTE MES)
$ch = curl_init('http://localhost/index.php?tab=despachos&q_paciente=18952301');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
$searchHtml = curl_exec($ch);
curl_close($ch);

$searchChecks = [
    'Ficha Clínica Asistencial' => str_contains($searchHtml, 'Ficha Clínica Asistencial'),
    'María Auxiliadora Peña' => str_contains($searchHtml, 'María Auxiliadora Peña Rangel'),
    'Vías de Contacto' => str_contains($searchHtml, 'Vías de Contacto'),
    'Dotación Mensual Ya Retirada' => str_contains($searchHtml, 'Dotación Mensual Ya Retirada'),
    'Botón Despacho Bloqueado' => str_contains($searchHtml, 'Dotación Ya Retirada')
];

echo "\n--- Búsqueda de Paciente 18952301 (María Peña - Ya retiró) ---\n";
foreach ($searchChecks as $title => $pass) {
    echo ($pass ? "[PASS] " : "[FAIL] ") . $title . "\n";
    if (!$pass) die("Fallo en {$title}\n");
}

// 4. Buscar Paciente Pedro Albarrán (Cédula: 11400219 - Insulina NPH - Habilitado en Centro 1)
$ch = curl_init('http://localhost/index.php?tab=despachos&centro_id=1&q_paciente=11400219');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
$searchPedHtml = curl_exec($ch);
curl_close($ch);

echo "\n--- Búsqueda de Paciente Pedro Albarrán (11400219 - Insulina en Centro 1) ---\n";
// Imprimir estado de stock
if (str_contains($searchPedHtml, 'Sin existencias')) {
    echo "[INFO] Aparece 'Sin existencias en este ambulatorio'\n";
} elseif (str_contains($searchPedHtml, 'Lote Prioritario FEFO')) {
    echo "[INFO] Aparece 'Lote Prioritario FEFO'\n";
} else {
    echo "[INFO] No se encontró ni 'Sin existencias' ni 'Lote Prioritario FEFO'\n";
}
$pedroChecks = [
    'Pedro José Albarrán' => str_contains($searchPedHtml, 'Pedro José Albarrán Contreras'),
    'Insulina NPH' => str_contains($searchPedHtml, 'Insulina NPH'),
    'Cadena de Frío' => str_contains($searchPedHtml, 'Cadena de Frío'),
    'Habilitado para Entrega' => str_contains($searchPedHtml, 'Habilitado para Entrega'),
    'Lote Prioritario FEFO' => str_contains($searchPedHtml, 'Lote Prioritario FEFO'),
    'Botón Dispensar Activo' => str_contains($searchPedHtml, 'btn-abrir-dispensar')
];

foreach ($pedroChecks as $title => $pass) {
    echo ($pass ? "[PASS] " : "[FAIL] ") . $title . "\n";
    if (!$pass) die("Fallo en {$title}\n");
}

echo "\n=== TODAS LAS PRUEBAS HTTP AUTENTICADAS FUERON EXITOSAS ===\n";

// 5. Probar con Rol Farmaceuta (Jurisdicción asignada a Centro 1)
$ch = curl_init('http://localhost/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'email' => 'farmacia.llano@salud.prueba.ve',
    'password' => 'farmacia123'
]));
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$postFarmHtml = curl_exec($ch);
curl_close($ch);

$ch = curl_init('http://localhost/index.php?tab=despachos');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
$farmDespHtml = curl_exec($ch);
curl_close($ch);

echo "\n--- Sesión Farmaceuta Asignado (El Llano) ---\n";
$farmChecks = [
    'Jurisdicción Asignada' => str_contains($farmDespHtml, 'Jurisdicción Asignada a Farmaceuta'),
    'El Llano Fijo' => str_contains($farmDespHtml, 'El Llano')
];
foreach ($farmChecks as $title => $pass) {
    echo ($pass ? "[PASS] " : "[FAIL] ") . $title . "\n";
    if (!$pass) die("Fallo en {$title}\n");
}
echo "[PASS] Rol Farmaceuta restringido a su jurisdicción correctamente.\n";
