<?php
// src/config/modules.php
declare(strict_types=1);

function obtenerModulosDisponibles(): array {
    return [
        'centros'       => true,
        'medicamentos'  => true,
        'pacientes'     => true,
        'recipes'       => true,
        'despachos'     => true,
        'maestras'      => true,
        'usuarios'      => true,
        'auditoria'     => true,
        'configuracion' => true,
    ];
}

function esModuloActivo(string $modulo): bool {
    $modulos = obtenerModulosDisponibles();
    return !empty($modulos[$modulo]);
}
