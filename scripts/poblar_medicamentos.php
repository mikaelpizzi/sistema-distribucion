<?php
// src/scripts/poblar_medicamentos.php
// Generador Masivo de Medicamentos para Pruebas de Carga y Rendimiento InnoDB (16 KB)
// Uso CLI:
//   php src/scripts/poblar_medicamentos.php [cantidad]
//   php src/scripts/poblar_medicamentos.php --limpiar
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (php_sapi_name() !== 'cli' && empty($_GET['ejecutar_web'])) {
    die("Este script debe ejecutarse desde la terminal (CLI).\n");
}

$pdo = getDB();
$argumento = $argv[1] ?? ($_GET['accion'] ?? '1000');

// 1. MODO LIMPIEZA (--limpiar)
if ($argumento === '--limpiar' || $argumento === 'limpiar') {
    echo "========================================================\n";
    echo "  LIMPIEZA DE CATÁLOGO: Eliminando registros generados  \n";
    echo "========================================================\n";

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM medicamentos WHERE id > 0");
        $stmt->execute();
        $eliminados = $stmt->rowCount();
        $pdo->commit();

        $pdo->exec("ALTER TABLE medicamentos AUTO_INCREMENT = 1");
        echo "✓ Se eliminaron {$eliminados} registros de medicamentos de prueba.\n";
        exit(0);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "✗ Error durante la limpieza: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// 2. MODO POBLADO MASIVO
$cantidadTotal = max(1, (int)$argumento);
$tamanoLote = 500; // 500 filas por bloque para respetar Buffer Pool de 16KB

echo "========================================================\n";
echo "  GENERADOR MASIVO DE MEDICAMENTOS (INNODB 16 KB / 3FN)  \n";
echo "========================================================\n";
echo "Registros solicitados: " . number_format($cantidadTotal) . "\n";
echo "Tamaño de bloque:      {$tamanoLote} filas\n";
echo "Iniciando transacción ACID...\n\n";

$inicioTiempo = microtime(true);

$familias = [
    ['principio' => 'Losartán Potásico',          'conc' => ['50mg', '100mg'],              'forma' => 1, 'frio' => 0],
    ['principio' => 'Metformina Clorhidrato',     'conc' => ['500mg', '850mg', '1000mg'],   'forma' => 1, 'frio' => 0],
    ['principio' => 'Amlodipino Besilato',         'conc' => ['5mg', '10mg'],                'forma' => 1, 'frio' => 0],
    ['principio' => 'Enalapril Maleato',           'conc' => ['10mg', '20mg'],               'forma' => 1, 'frio' => 0],
    ['principio' => 'Atorvastatina Cálcica',       'conc' => ['10mg', '20mg', '40mg'],       'forma' => 1, 'frio' => 0],
    ['principio' => 'Rosuvastatina Sódica',        'conc' => ['10mg', '20mg'],               'forma' => 1, 'frio' => 0],
    ['principio' => 'Bisoprolol Fumarato',         'conc' => ['2.5mg', '5mg', '10mg'],       'forma' => 1, 'frio' => 0],
    ['principio' => 'Carvedilol',                  'conc' => ['6.25mg', '12.5mg', '25mg'],   'forma' => 1, 'frio' => 0],
    ['principio' => 'Insulina Glargina',           'conc' => ['100UI/ml'],                   'forma' => 3, 'frio' => 1],
    ['principio' => 'Insulina NPH Humana',         'conc' => ['100UI/ml'],                   'forma' => 3, 'frio' => 1],
    ['principio' => 'Salbutamol Sulfato',          'conc' => ['100mcg/dosis'],               'forma' => 4, 'frio' => 0],
    ['principio' => 'Budesonida',                  'conc' => ['200mcg', '400mcg'],           'forma' => 4, 'frio' => 0],
    ['principio' => 'Omeprazol Magnésico',         'conc' => ['20mg', '40mg'],               'forma' => 2, 'frio' => 0],
    ['principio' => 'Levotiroxina Sódica',         'conc' => ['25mcg', '50mcg', '100mcg'],   'forma' => 1, 'frio' => 0],
    ['principio' => 'Paracetamol (Acetaminofén)',  'conc' => ['500mg', '650mg', '1g'],       'forma' => 1, 'frio' => 0],
    ['principio' => 'Pregabalina',                 'conc' => ['75mg', '150mg'],              'forma' => 2, 'frio' => 0],
    ['principio' => 'Sertralina Clorhidrato',      'conc' => ['50mg', '100mg'],              'forma' => 1, 'frio' => 0],
    ['principio' => 'Ácido Acetilsalicílico',      'conc' => ['81mg', '100mg'],              'forma' => 1, 'frio' => 0],
    ['principio' => 'Clopidogrel Bisulfato',       'conc' => ['75mg'],                       'forma' => 1, 'frio' => 0],
    ['principio' => 'Ibuprofeno Arginina',         'conc' => ['400mg', '600mg'],             'forma' => 1, 'frio' => 0],
];

$variantes = ['Genérico MPPS', 'Fórmula Retard', 'Liberación Prolongada', 'Cápsula Blanda', 'Comprimido Ranurado', 'Dosis Estándar'];

$pdo->beginTransaction();

try {
    $insertados = 0;
    $loteValores = [];
    $lotePlaceholders = [];

    for ($i = 1; $i <= $cantidadTotal; $i++) {
        $fam = $familias[($i - 1) % count($familias)];
        $var = $variantes[intdiv($i, count($familias)) % count($variantes)];
        $conc = $fam['conc'][$i % count($fam['conc'])];
        $nombreCompleto = $fam['principio'] . ' ' . $conc . ' (' . $var . ' #' . $i . ')';
        $formaId = $fam['forma'];
        $frio = $fam['frio'];

        $lotePlaceholders[] = '(?, ?, ?, ?, 1)';
        $loteValores[] = $nombreCompleto;
        $loteValores[] = $conc;
        $loteValores[] = $formaId;
        $loteValores[] = $frio;

        if (count($lotePlaceholders) === $tamanoLote || $i === $cantidadTotal) {
            $sql = "INSERT INTO medicamentos (nombre_generico, concentracion, forma_farmaceutica_id, requiere_cadena_frio, activo) VALUES " 
                 . implode(', ', $lotePlaceholders);
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($loteValores);

            $insertados += count($lotePlaceholders);
            $lotePlaceholders = [];
            $loteValores = [];
        }
    }

    $pdo->commit();

    $tiempoTotal = round(microtime(true) - $inicioTiempo, 3);
    $totalEnBd = (int)$pdo->query("SELECT COUNT(*) FROM medicamentos WHERE activo = 1")->fetchColumn();

    echo "✓ INSERCIÓN MASIVA COMPLETADA CON ÉXITO\n";
    echo "========================================================\n";
    echo "Filas insertadas:    " . number_format($insertados) . "\n";
    echo "Total activo en BD:  " . number_format($totalEnBd) . " medicamentos\n";
    echo "Tiempo transcurrido: {$tiempoTotal} segundos\n";
    echo "Velocidad promedio:  " . round($insertados / max(0.001, $tiempoTotal)) . " filas/segundo\n";
    echo "========================================================\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n✗ Error crítico: " . $e->getMessage() . "\n";
    exit(1);
}