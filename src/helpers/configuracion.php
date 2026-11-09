<?php
// src/helpers/configuracion.php
// Helper de Identidad Institucional y Parametrización Central (White-labeling / Cero Datos Quemados)
declare(strict_types=1);

/**
 * Obtiene la configuración institucional activa del sistema desde la base de datos.
 * Aplica fallback automático ante fallas o tablas no inicializadas.
 *
 * @param PDO $pdo Instancia activa de conexión a la base de datos.
 * @return array{
 *     id: int,
 *     nombre_sistema: string,
 *     siglas_sistema: string,
 *     ente_rector: string,
 *     municipio: string,
 *     logotipo_ruta: ?string,
 *     dias_alerta_fefo: int,
 *     updated_at?: ?string
 * }
 */
function obtenerConfiguracionInstitucional(PDO $pdo): array
{
    static $cacheConfig = null;
    if ($cacheConfig !== null) {
        return $cacheConfig;
    }

    $fallback = [
        'id'               => 1,
        'nombre_sistema'   => 'Sistema Integrado de Control de Medicamentos',
        'siglas_sistema'   => 'SICM',
        'ente_rector'      => 'Red de Salud Municipal',
        'municipio'        => 'Municipio Autónomo',
        'logotipo_ruta'    => null,
        'dias_alerta_fefo' => 60,
        'updated_at'       => null
    ];

    try {
        $stmt = $pdo->query("SELECT * FROM configuracion_institucional WHERE id = 1 LIMIT 1");
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $cacheConfig = [
                    'id'               => 1,
                    'nombre_sistema'   => !empty($row['nombre_sistema']) ? (string)$row['nombre_sistema'] : $fallback['nombre_sistema'],
                    'siglas_sistema'   => !empty($row['siglas_sistema']) ? (string)$row['siglas_sistema'] : $fallback['siglas_sistema'],
                    'ente_rector'      => !empty($row['ente_rector']) ? (string)$row['ente_rector'] : $fallback['ente_rector'],
                    'municipio'        => !empty($row['municipio']) ? (string)$row['municipio'] : $fallback['municipio'],
                    'logotipo_ruta'    => !empty($row['logotipo_ruta']) ? (string)$row['logotipo_ruta'] : null,
                    'dias_alerta_fefo' => isset($row['dias_alerta_fefo']) && is_numeric($row['dias_alerta_fefo']) ? (int)$row['dias_alerta_fefo'] : 60,
                    'updated_at'       => $row['updated_at'] ?? null,
                ];
                return $cacheConfig;
            }
        }
    } catch (Throwable $e) {
        error_log('[CONFIG INSTITUCIONAL FALLBACK]: ' . $e->getMessage());
    }

    $cacheConfig = $fallback;
    return $cacheConfig;
}
