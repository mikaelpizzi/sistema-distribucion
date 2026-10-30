<?php
// src/scripts/poblar_medicamentos.php
// Script CLI para generación masiva de medicamentos con Multi-row Prepared Statements
// Uso:
//   php src/scripts/poblar_medicamentos.php [cantidad]
//   php src/scripts/poblar_medicamentos.php --limpiar

require_once __DIR__ . '/../config/db.php';

// Solo permitir ejecución en línea de comandos (CLI) o verificación interna
if (php_sapi_name() !== 'cli' && empty($_GET['ejecutar_web'])) {
    die("Este script debe ejecutarse desde la terminal (CLI).\n");
}

$pdo = getDB();
$argumento = $argv[1] ?? ($_GET['accion'] ?? '10000');

// ==============================================================================
// 1. MODO LIMPIEZA (--limpiar): Restaura el catálogo a los 5 registros base
// ==============================================================================
if ($argumento === '--limpiar' || $argumento === 'limpiar') {
    echo "========================================================\n";
    echo "  LIMPIEZA DE CATÁLOGO: Eliminando registros generados  \n";
    echo "========================================================\n";

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("DELETE FROM medicamentos WHERE id > 5 OR codigo_atc LIKE 'GEN-%'");
        $stmt->execute();
        $eliminados = $stmt->rowCount();

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        // Reiniciar el auto-increment si solo quedaron los iniciales (DDL fuera de transacción)
        $totalRestantes = (int)$pdo->query("SELECT COUNT(*) FROM medicamentos")->fetchColumn();
        if ($totalRestantes <= 5) {
            $pdo->exec("ALTER TABLE medicamentos AUTO_INCREMENT = 6");
        }

        echo "✓ Se eliminaron {$eliminados} registros generados.\n";
        echo "✓ Catálogo restaurado a los registros base ({$totalRestantes} activos).\n";
        exit(0);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "✗ Error durante la limpieza: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ==============================================================================
// 2. MODO POBLADO MASIVO: Inserción por Lotes (Batch Multi-row)
// ==============================================================================
$cantidadTotal = max(1, (int)$argumento);
$tamanoLote = 500; // 500 filas * 5 columnas = 2.500 parámetros (muy seguro bajo el límite de 65k)

echo "========================================================\n";
echo "  GENERADOR MASIVO DE MEDICAMENTOS (100% 3FN + INNODB)   \n";
echo "========================================================\n";
echo "Registros a generar: " . number_format($cantidadTotal) . "\n";
echo "Tamaño de cada lote: {$tamanoLote} filas\n";
echo "Iniciando proceso...\n\n";

$inicioTiempo = microtime(true);

// Catálogo base de familias terapéuticas reales de la OMS
$familias = [
    ['prefijo' => 'C09CA', 'principio' => 'Losartán Potásico',     'conc' => ['50mg', '100mg'],          'forma' => 1, 'frio' => 0],
    ['prefijo' => 'A10BA', 'principio' => 'Metformina Clorhidrato', 'conc' => ['500mg', '850mg', '1000mg'], 'forma' => 1, 'frio' => 0],
    ['prefijo' => 'C08CA', 'principio' => 'Amlodipino Besilato',    'conc' => ['5mg', '10mg'],            'forma' => 1, 'frio' => 0],
    ['prefijo' => 'C09AA', 'principio' => 'Enalapril Maleato',      'conc' => ['10mg', '20mg'],           'forma' => 1, 'frio' => 0],
    ['prefijo' => 'C10AA', 'principio' => 'Atorvastatina Cálcica',  'conc' => ['10mg', '20mg', '40mg'],   'forma' => 1, 'frio' => 0],
    ['prefijo' => 'C10AB', 'principio' => 'Rosuvastatina Sódica',   'conc' => ['10mg', '20mg'],           'forma' => 1, 'frio' => 0],
    ['prefijo' => 'C07AB', 'principio' => 'Bisoprolol Fumarato',    'conc' => ['2.5mg', '5mg', '10mg'],   'forma' => 1, 'frio' => 0],
    ['prefijo' => 'C07AG', 'principio' => 'Carvedilol',             'conc' => ['6.25mg', '12.5mg', '25mg'],'forma' => 1, 'frio' => 0],
    ['prefijo' => 'A10AE', 'principio' => 'Insulina Glargina',      'conc' => ['100UI/ml'],               'forma' => 3, 'frio' => 1],
    ['prefijo' => 'A10AC', 'principio' => 'Insulina NPH Humana',    'conc' => ['100UI/ml'],               'forma' => 3, 'frio' => 1],
    ['prefijo' => 'R03AC', 'principio' => 'Salbutamol Sulfato',     'conc' => ['100mcg'],                 'forma' => 4, 'frio' => 0],
    ['prefijo' => 'R03BA', 'principio' => 'Budesonida',             'conc' => ['200mcg', '400mcg'],       'forma' => 4, 'frio' => 0],
    ['prefijo' => 'A02BC', 'principio' => 'Omeprazol Magnésico',    'conc' => ['20mg', '40mg'],           'forma' => 2, 'frio' => 0],
    ['prefijo' => 'H03AA', 'principio' => 'Levotiroxina Sódica',    'conc' => ['25mcg', '50mcg', '100mcg'],'forma' => 1, 'frio' => 0],
    ['prefijo' => 'N02BE', 'principio' => 'Paracetamol (Acetaminofén)','conc' => ['500mg', '650mg', '1g'],'forma' => 1, 'frio' => 0],
    ['prefijo' => 'N03AX', 'principio' => 'Pregabalina',            'conc' => ['75mg', '150mg'],          'forma' => 2, 'frio' => 0],
    ['prefijo' => 'N06AB', 'principio' => 'Sertralina Clorhidrato', 'conc' => ['50mg', '100mg'],         'forma' => 1, 'frio' => 0],
    ['prefijo' => 'B01AC', 'principio' => 'Ácido Acetilsalicílico', 'conc' => ['81mg', '100mg'],         'forma' => 1, 'frio' => 0],
    ['prefijo' => 'B01AF', 'principio' => 'Clopidogrel Bisulfato',  'conc' => ['75mg'],                   'forma' => 1, 'frio' => 0],
    ['prefijo' => 'M01AE', 'principio' => 'Ibuprofeno Arginina',    'conc' => ['400mg', '600mg'],         'forma' => 1, 'frio' => 0],
];

$variantes = ['Genérico', 'Fórmula Retard', 'Liberación Prolongada', 'Cápsula Blanda', 'Comprimido Ranurado', 'Dosis Pediátrica', 'Solución'];

$pdo->beginTransaction();

try {
    $insertados = 0;
    $loteValores = [];
    $lotePlaceholders = [];

    for ($i = 1; $i <= $cantidadTotal; $i++) {
        $fam = $familias[($i - 1) % count($familias)];
        $indiceVar = intdiv($i, count($familias)) % count($variantes);
        $var = $variantes[$indiceVar];
        $conc = $fam['conc'][$i % count($fam['conc'])];

        // Código ATC único correlativo (ej: GEN-C09CA-00001)
        $codigoAtc = sprintf('GEN-%s-%05d', $fam['prefijo'], $i);
        $nombreCompleto = $fam['principio'] . ' (' . $var . ')';
        $formaId = $fam['forma'];
        $frio = $fam['frio'];

        // Agregar parámetros para el multi-row statement
        $lotePlaceholders[] = '(?, ?, ?, ?, ?)';
        $loteValores[] = $codigoAtc;
        $loteValores[] = $nombreCompleto;
        $loteValores[] = $conc;
        $loteValores[] = $formaId;
        $loteValores[] = $frio;

        // Cuando alcanzamos el tamaño del lote o la última fila, ejecutamos el multi-row INSERT
        if (count($lotePlaceholders) === $tamanoLote || $i === $cantidadTotal) {
            $sql = "INSERT INTO medicamentos (codigo_atc, nombre_generico, concentracion, forma_farmaceutica_id, requiere_cadena_frio) VALUES " 
                 . implode(', ', $lotePlaceholders);
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($loteValores);

            $insertados += count($lotePlaceholders);
            $lotePlaceholders = [];
            $loteValores = [];

            // Barra de progreso en consola
            $porcentaje = round(($insertados / $cantidadTotal) * 100);
            echo " Progreso: {$porcentaje}% (" . number_format($insertados) . " / " . number_format($cantidadTotal) . ")\r";
        }
    }

    $pdo->commit();

    $tiempoTotal = round(microtime(true) - $inicioTiempo, 3);
    $memoria = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

    $totalEnBd = (int)$pdo->query("SELECT COUNT(*) FROM medicamentos WHERE deleted_at IS NULL")->fetchColumn();

    echo "\n\n========================================================\n";
    echo "✓ INSERCIÓN MASIVA COMPLETADA CON ÉXITO\n";
    echo "========================================================\n";
    echo "Filas insertadas:    " . number_format($insertados) . "\n";
    echo "Total activo en BD:  " . number_format($totalEnBd) . " medicamentos\n";
    echo "Tiempo transcurrido: {$tiempoTotal} segundos\n";
    echo "Memoria PHP pico:    {$memoria} MB\n";
    echo "Velocidad promedio:  " . round($insertados / max(0.001, $tiempoTotal)) . " filas/segundo\n";
    echo "\nPara revertir y limpiar estos registros generados, ejecuta:\n";
    echo "  php src/scripts/poblar_medicamentos.php --limpiar\n";
    echo "========================================================\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n✗ Error crítico: " . $e->getMessage() . "\n";
    echo "Se ejecutó ROLLBACK automático. La base de datos no fue afectada.\n";
    exit(1);
}
