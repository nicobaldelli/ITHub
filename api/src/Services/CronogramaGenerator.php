<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use DateTimeImmutable;
use DateTimeInterface;
use ITHub\Api\Models\Servicio;

/**
 * Helper PURO (sin DB) que genera el cronograma de cuotas de un servicio.
 *
 * Casos cubiertos:
 *  1) Proyecto — N cuotas con porcentajes ingresados por el usuario
 *  2) Mantenimiento `mes_calendario` con `fecha_fin` definida — última cuota proporcional si fecha_fin
 *     no coincide con día_facturacion
 *  3) Mantenimiento `mes_calendario` indefinido — rolling window de `windowMonths` cuotas
 *  4) Mantenimiento `intervalo_dias` con `fecha_fin` — última cuota proporcional si el período no
 *     divide en intervalos exactos
 *  5) Mantenimiento `intervalo_dias` indefinido — rolling window equivalente
 *
 * Reglas de proporcionalidad:
 *  - Solo la ÚLTIMA cuota puede ser proporcional, y solo si fecha_fin no es divisible exacta
 *  - Importe proporcional = importe_base * dias_cubiertos / dias_esperados
 *  - Si una cuota cubriría 0 días, NO se genera (caso fecha_fin == fecha de cuota)
 */
final class CronogramaGenerator
{
    public const DEFAULT_WINDOW_MONTHS = 12;

    private const MESES_ES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * Genera el cronograma de cuotas.
     *
     * @param Servicio $servicio  el servicio configurado (no necesita estar persistido)
     * @param array<int,array{porcentaje: float|string, fecha_prevista: string, etiqueta?: string}> $proyectoCuotas
     *        Para PROYECTO: las cuotas ingresadas por el usuario (porcentajes + fechas + etiquetas opcionales).
     *        Se ignora si el servicio es mantenimiento.
     * @param int $windowMonths Para mantenimientos INDEFINIDOS: cuántos meses hacia adelante generar.
     *
     * @return array<int, array<string, mixed>> Cuotas listas para insertar en `servicio_cuotas`.
     */
    public static function generar(
        Servicio $servicio,
        array $proyectoCuotas = [],
        int $windowMonths = self::DEFAULT_WINDOW_MONTHS,
    ): array {
        if ($servicio->esProyecto()) {
            return self::generarProyecto($servicio, $proyectoCuotas);
        }
        return self::generarMantenimiento($servicio, $windowMonths);
    }

    // ============================================================
    // PROYECTOS
    // ============================================================

    /**
     * @param array<int, array{porcentaje: float|string, fecha_prevista: string, etiqueta?: string}> $cuotas
     */
    private static function generarProyecto(Servicio $servicio, array $cuotas): array
    {
        $total = count($cuotas);
        $importeBase = (float) $servicio->importe_base;
        $resultado = [];

        foreach ($cuotas as $i => $c) {
            $porc = (float) $c['porcentaje'];
            $resultado[] = [
                'numero_cuota' => $i + 1,
                'total_cuotas' => $total,
                'porcentaje' => $porc,
                'importe' => round($importeBase * $porc / 100, 2),
                'fecha_prevista' => (string) $c['fecha_prevista'],
                'etiqueta' => isset($c['etiqueta']) && trim((string) $c['etiqueta']) !== ''
                    ? trim((string) $c['etiqueta'])
                    : sprintf('Cuota %d de %d', $i + 1, $total),
                'es_proporcional' => false,
                'dias_cubiertos' => null,
            ];
        }
        return $resultado;
    }

    // ============================================================
    // MANTENIMIENTOS
    // ============================================================

    private static function generarMantenimiento(Servicio $servicio, int $windowMonths): array
    {
        return $servicio->modo_facturacion === Servicio::MODO_MES_CALENDARIO
            ? self::generarMesCalendario($servicio, $windowMonths)
            : self::generarIntervaloDias($servicio, $windowMonths);
    }

    // ---------- mes_calendario ----------

    private static function generarMesCalendario(Servicio $servicio, int $windowMonths): array
    {
        $importeBase = (float) $servicio->importe_base;
        $dia = (int) ($servicio->dia_facturacion ?? 1);
        $fechaInicio = self::toDate($servicio->fecha_inicio);
        $fechaFin = $servicio->fecha_fin ? self::toDate($servicio->fecha_fin) : null;
        $indefinido = $fechaFin === null;

        $primera = self::proximoDiaDelMes($fechaInicio, $dia);

        // Lista de fechas previstas
        $fechas = [];
        $cursor = $primera;
        $safety = 10_000; // anti-loop

        if ($indefinido) {
            for ($i = 0; $i < $windowMonths && $safety-- > 0; $i++) {
                $fechas[] = $cursor;
                $cursor = self::proximoDiaDelMes($cursor->modify('+1 day'), $dia);
            }
        } else {
            while ($cursor <= $fechaFin && $safety-- > 0) {
                $fechas[] = $cursor;
                $cursor = self::proximoDiaDelMes($cursor->modify('+1 day'), $dia);
            }
        }

        $totalCuotas = $indefinido ? null : count($fechas);
        $cuotas = [];

        foreach ($fechas as $i => $fecha) {
            $esUltima = ($i === count($fechas) - 1);
            $esProporcional = false;
            $diasCubiertos = null;
            $importe = $importeBase;

            if ($esUltima && !$indefinido && $fechaFin !== null) {
                // La cuota cubre desde $fecha hasta la próxima ocurrencia (o fecha_fin si es antes).
                $proximaNormal = self::proximoDiaDelMes($fecha->modify('+1 day'), $dia);
                $diasEsperados = self::diffDays($fecha, $proximaNormal);
                $diasReales = self::diffDays($fecha, $fechaFin);

                // Si la cuota cubriría 0 días (fecha_fin coincide con fecha de la cuota), la salteamos.
                if ($diasReales <= 0) {
                    continue;
                }

                if ($diasReales < $diasEsperados) {
                    $esProporcional = true;
                    $diasCubiertos = $diasReales;
                    $importe = round($importeBase * $diasReales / $diasEsperados, 2);
                }
            }

            $cuotas[] = [
                'numero_cuota' => count($cuotas) + 1,
                'total_cuotas' => $totalCuotas,
                'porcentaje' => null,
                'importe' => $importe,
                'fecha_prevista' => $fecha->format('Y-m-d'),
                'etiqueta' => self::etiquetaMesCalendario($fecha, count($cuotas) + 1, $totalCuotas, $indefinido, $esProporcional, $diasCubiertos),
                'es_proporcional' => $esProporcional,
                'dias_cubiertos' => $diasCubiertos,
            ];
        }

        // Si saltamos la última (0 días), corregimos total_cuotas
        if (!$indefinido && count($cuotas) !== $totalCuotas) {
            $nuevoTotal = count($cuotas);
            foreach ($cuotas as &$c) {
                $c['total_cuotas'] = $nuevoTotal;
                // re-etiquetar
                $fecha = new DateTimeImmutable($c['fecha_prevista']);
                $c['etiqueta'] = self::etiquetaMesCalendario(
                    $fecha,
                    $c['numero_cuota'],
                    $nuevoTotal,
                    false,
                    $c['es_proporcional'],
                    $c['dias_cubiertos'],
                );
            }
            unset($c);
        }

        return $cuotas;
    }

    // ---------- intervalo_dias ----------

    private static function generarIntervaloDias(Servicio $servicio, int $windowMonths): array
    {
        $importeBase = (float) $servicio->importe_base;
        $intervalo = max(1, (int) ($servicio->intervalo_dias ?? 30));
        $fechaInicio = self::toDate($servicio->fecha_inicio);
        $fechaFin = $servicio->fecha_fin ? self::toDate($servicio->fecha_fin) : null;
        $indefinido = $fechaFin === null;

        $cuotas = [];
        $cursor = $fechaInicio;
        $numero = 1;

        if ($indefinido) {
            // Aproximamos: windowMonths * 30 / intervalo redondeado hacia arriba
            $cantidad = max(1, (int) ceil(($windowMonths * 30) / $intervalo));
            for ($i = 0; $i < $cantidad; $i++) {
                $cuotas[] = [
                    'numero_cuota' => $numero++,
                    'total_cuotas' => null,
                    'porcentaje' => null,
                    'importe' => $importeBase,
                    'fecha_prevista' => $cursor->format('Y-m-d'),
                    'etiqueta' => sprintf('Cuota %d', $i + 1),
                    'es_proporcional' => false,
                    'dias_cubiertos' => null,
                ];
                $cursor = $cursor->modify('+' . $intervalo . ' days');
            }
            return $cuotas;
        }

        // Definido: contar cuotas completas + posible proporcional
        $totalDias = self::diffDays($fechaInicio, $fechaFin);
        if ($totalDias <= 0) {
            return [];
        }
        $cuotasCompletas = (int) floor($totalDias / $intervalo);
        $diasRemanente = $totalDias - ($cuotasCompletas * $intervalo);
        $hayProporcional = $diasRemanente > 0;
        $totalCuotas = $cuotasCompletas + ($hayProporcional ? 1 : 0);

        for ($i = 0; $i < $cuotasCompletas; $i++) {
            $cuotas[] = [
                'numero_cuota' => $numero,
                'total_cuotas' => $totalCuotas,
                'porcentaje' => null,
                'importe' => $importeBase,
                'fecha_prevista' => $cursor->format('Y-m-d'),
                'etiqueta' => sprintf('Cuota %d de %d', $numero, $totalCuotas),
                'es_proporcional' => false,
                'dias_cubiertos' => null,
            ];
            $numero++;
            $cursor = $cursor->modify('+' . $intervalo . ' days');
        }

        if ($hayProporcional) {
            $importeProp = round($importeBase * $diasRemanente / $intervalo, 2);
            $cuotas[] = [
                'numero_cuota' => $numero,
                'total_cuotas' => $totalCuotas,
                'porcentaje' => null,
                'importe' => $importeProp,
                'fecha_prevista' => $cursor->format('Y-m-d'),
                'etiqueta' => sprintf('Cuota %d de %d (proporcional %d días)', $numero, $totalCuotas, $diasRemanente),
                'es_proporcional' => true,
                'dias_cubiertos' => $diasRemanente,
            ];
        }

        return $cuotas;
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Próxima ocurrencia del día `$day` (1-31) del mes >= $from.
     * Si el mes objetivo no tiene ese día (ej: febrero y day=31), usa el último día del mes.
     */
    public static function proximoDiaDelMes(DateTimeImmutable $from, int $day): DateTimeImmutable
    {
        $day = max(1, min(31, $day));
        $year = (int) $from->format('Y');
        $month = (int) $from->format('n');
        $fromDay = (int) $from->format('j');

        if ($fromDay > $day) {
            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        $firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastDay = (int) $firstOfMonth->modify('last day of this month')->format('j');
        $actualDay = min($day, $lastDay);

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $actualDay));
    }

    private static function toDate(mixed $d): DateTimeImmutable
    {
        if ($d instanceof DateTimeImmutable) {
            return $d;
        }
        if ($d instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($d);
        }
        return new DateTimeImmutable((string) $d);
    }

    private static function diffDays(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return (int) $a->diff($b)->days * ($b >= $a ? 1 : -1);
    }

    private static function etiquetaMesCalendario(
        DateTimeImmutable $fecha,
        int $numero,
        ?int $total,
        bool $indefinido,
        bool $esProporcional,
        ?int $diasCubiertos,
    ): string {
        $nombreMes = self::MESES_ES[(int) $fecha->format('n')] . ' ' . $fecha->format('Y');
        if ($indefinido) {
            return $nombreMes;
        }
        $base = sprintf('%d de %d — %s', $numero, $total, $nombreMes);
        if ($esProporcional && $diasCubiertos !== null) {
            $base .= sprintf(' (proporcional %d días)', $diasCubiertos);
        }
        return $base;
    }
}
