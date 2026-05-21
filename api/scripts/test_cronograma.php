<?php

/**
 * Script de smoke test del CronogramaGenerator.
 * Correr: docker compose exec api php scripts/test_cronograma.php
 *
 * No toca DB — usa modelos in-memory para verificar la lógica pura.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Services\CronogramaGenerator;

// Cargamos .env para tomar las credenciales locales
if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

// Boot Eloquent (necesario para que los modelos respondan a fill/casts).
$capsule = new Capsule();
$capsule->addConnection([
    'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
    'host' => $_ENV['DB_HOST'] ?? 'db',
    'database' => $_ENV['DB_NAME'] ?? 'ithub',
    'username' => $_ENV['DB_USER'] ?? 'ithub',
    'password' => $_ENV['DB_PASS'] ?? '',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

function nuevoServicio(array $data): Servicio
{
    $s = new Servicio();
    $s->fill($data);
    // Para los campos que no van en fillable
    if (isset($data['tipo'])) {
        $s->tipo = $data['tipo'];
    }
    return $s;
}

function imprimirCuotas(string $titulo, array $cuotas): void
{
    echo "\n=== {$titulo} ===\n";
    echo sprintf("%-3s %-10s %-12s %-10s %-6s %s\n", '#', 'Fecha', 'Importe', 'Propor?', 'Días', 'Etiqueta');
    echo str_repeat('-', 90) . "\n";
    foreach ($cuotas as $c) {
        echo sprintf(
            "%-3d %-10s %-12s %-10s %-6s %s\n",
            $c['numero_cuota'],
            $c['fecha_prevista'],
            number_format((float) $c['importe'], 2),
            $c['es_proporcional'] ? 'SÍ' : '',
            $c['dias_cubiertos'] ?? '',
            $c['etiqueta'],
        );
    }
    echo sprintf("(%d cuotas)\n", count($cuotas));
}

// =============================================================
// Caso 1: PROYECTO con 3 cuotas (anticipo + hito + cierre)
// =============================================================
$s1 = nuevoServicio([
    'tipo' => 'proyecto',
    'nombre' => 'Implementación CRM',
    'moneda' => 'ARS',
    'importe_base' => 1_000_000,
    'fecha_inicio' => '2026-06-01',
]);
$cuotas1 = CronogramaGenerator::generar($s1, [
    ['porcentaje' => 30, 'fecha_prevista' => '2026-06-01', 'etiqueta' => 'Anticipo'],
    ['porcentaje' => 40, 'fecha_prevista' => '2026-07-15', 'etiqueta' => 'Hito 1'],
    ['porcentaje' => 30, 'fecha_prevista' => '2026-09-01', 'etiqueta' => 'Cierre'],
]);
imprimirCuotas('1) PROYECTO — 30/40/30 de $1.000.000', $cuotas1);

// =============================================================
// Caso 2: MANTENIMIENTO mes_calendario definido — última proporcional
// inicio: 2026-06-10, fin: 2026-09-15, día_facturacion: 5
// Primera cuota: 2026-07-05, después 08-05, después 09-05
// La 09-05 cubre del 09-05 al 09-15 = 10 días (< 30) → proporcional
// =============================================================
$s2 = nuevoServicio([
    'tipo' => 'mantenimiento',
    'nombre' => 'Mantenimiento mensual',
    'moneda' => 'ARS',
    'importe_base' => 100_000,
    'fecha_inicio' => '2026-06-10',
    'fecha_fin' => '2026-09-15',
    'modo_facturacion' => 'mes_calendario',
    'dia_facturacion' => 5,
]);
$cuotas2 = CronogramaGenerator::generar($s2);
imprimirCuotas('2) MANTENIMIENTO mes_calendario definido — última proporcional', $cuotas2);

// =============================================================
// Caso 3: MANTENIMIENTO mes_calendario INDEFINIDO
// inicio: 2026-06-01, sin fecha_fin, día_facturacion: 15
// Genera 12 cuotas adelante, ninguna proporcional, total_cuotas = null
// =============================================================
$s3 = nuevoServicio([
    'tipo' => 'mantenimiento',
    'nombre' => 'Mantenimiento indefinido',
    'moneda' => 'USD',
    'importe_base' => 500,
    'fecha_inicio' => '2026-06-01',
    'modo_facturacion' => 'mes_calendario',
    'dia_facturacion' => 15,
]);
$cuotas3 = CronogramaGenerator::generar($s3);
imprimirCuotas('3) MANTENIMIENTO mes_calendario INDEFINIDO (12 cuotas)', $cuotas3);

// =============================================================
// Caso 4: MANTENIMIENTO intervalo_dias definido — última proporcional
// inicio: 2026-01-01, fin: 2026-04-15, intervalo: 30
// Total: ~104 días / 30 = 3 cuotas completas + 14 días proporcionales
// =============================================================
$s4 = nuevoServicio([
    'tipo' => 'mantenimiento',
    'nombre' => 'Soporte quincenal',
    'moneda' => 'ARS',
    'importe_base' => 60_000,
    'fecha_inicio' => '2026-01-01',
    'fecha_fin' => '2026-04-15',
    'modo_facturacion' => 'intervalo_dias',
    'intervalo_dias' => 30,
]);
$cuotas4 = CronogramaGenerator::generar($s4);
imprimirCuotas('4) MANTENIMIENTO intervalo_dias definido (30d) — última proporcional', $cuotas4);

// =============================================================
// Caso 5: Edge — día_facturacion 31 cruzando febrero
// =============================================================
$s5 = nuevoServicio([
    'tipo' => 'mantenimiento',
    'nombre' => 'Edge: día 31',
    'moneda' => 'ARS',
    'importe_base' => 50_000,
    'fecha_inicio' => '2026-01-15',
    'fecha_fin' => '2026-05-31',
    'modo_facturacion' => 'mes_calendario',
    'dia_facturacion' => 31,
]);
$cuotas5 = CronogramaGenerator::generar($s5);
imprimirCuotas('5) EDGE — día 31, cruza febrero (debe ajustar a 28)', $cuotas5);

echo "\n=== Tests OK ===\n";
