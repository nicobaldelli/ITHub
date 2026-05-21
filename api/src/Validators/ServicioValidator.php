<?php

declare(strict_types=1);

namespace ITHub\Api\Validators;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Servicio;

/**
 * Valida y normaliza el payload de creación/edición de servicios.
 *
 * Reglas según tipo:
 *  - proyecto:
 *      * fecha_fin requerida (los proyectos siempre cierran)
 *      * NO admite modo_facturacion / dia_facturacion / intervalo_dias / frecuencia_ajuste_meses
 *      * cuotas[]: requerido, suma de porcentajes = 100 ± 0.01
 *  - mantenimiento:
 *      * modo_facturacion requerido (mes_calendario | intervalo_dias)
 *      * Si modo=mes_calendario → dia_facturacion 1-31 requerido
 *      * Si modo=intervalo_dias → intervalo_dias >= 1 requerido
 *      * fecha_fin opcional (NULL = indefinido)
 *      * frecuencia_ajuste_meses opcional
 *      * NO admite cuotas[] (se generan automáticamente)
 */
final class ServicioValidator
{
    private const PORCENTAJE_TOLERANCIA = 0.01;

    /**
     * @param array<string,mixed> $data
     * @return array{servicio: array<string,mixed>, cuotas: array<int, array<string,mixed>>}
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $errors = [];

        // ---- Campos comunes ----
        $tipo = (string) ($data['tipo'] ?? '');
        if (!in_array($tipo, Servicio::TIPOS, true)) {
            $errors['tipo'] = 'Tipo inválido. Valores: ' . implode(', ', Servicio::TIPOS);
        }

        if (empty($data['cliente_id']) || !is_numeric($data['cliente_id']) || (int) $data['cliente_id'] <= 0) {
            $errors['cliente_id'] = 'Requerido y numérico positivo';
        }

        $nombre = trim((string) ($data['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 200) {
            $errors['nombre'] = 'Requerido, hasta 200 caracteres';
        }

        $moneda = (string) ($data['moneda'] ?? 'ARS');
        if (!in_array($moneda, Servicio::MONEDAS, true)) {
            $errors['moneda'] = 'Permitidos: ' . implode(', ', Servicio::MONEDAS);
        }

        if (!isset($data['importe_base']) || !is_numeric($data['importe_base']) || (float) $data['importe_base'] <= 0) {
            $errors['importe_base'] = 'Requerido, mayor a 0';
        }

        if (empty($data['fecha_inicio']) || !self::isDate((string) $data['fecha_inicio'])) {
            $errors['fecha_inicio'] = 'Requerida (formato YYYY-MM-DD)';
        }

        if (!empty($data['fecha_fin']) && !self::isDate((string) $data['fecha_fin'])) {
            $errors['fecha_fin'] = 'Fecha inválida';
        }

        if (!empty($data['fecha_inicio']) && !empty($data['fecha_fin']) && self::isDate($data['fecha_inicio']) && self::isDate($data['fecha_fin'])) {
            if (strtotime($data['fecha_fin']) <= strtotime($data['fecha_inicio'])) {
                $errors['fecha_fin'] = 'Debe ser posterior a fecha_inicio';
            }
        }

        // ---- Validación específica por tipo ----
        if ($tipo === Servicio::TIPO_PROYECTO) {
            self::validateProyecto($data, $errors);
        } elseif ($tipo === Servicio::TIPO_MANTENIMIENTO) {
            self::validateMantenimiento($data, $errors);
        }

        // ---- Otros campos opcionales ----
        if (isset($data['frecuencia_ajuste_meses']) && $data['frecuencia_ajuste_meses'] !== '' && $data['frecuencia_ajuste_meses'] !== null) {
            if (!is_numeric($data['frecuencia_ajuste_meses']) || (int) $data['frecuencia_ajuste_meses'] < 1) {
                $errors['frecuencia_ajuste_meses'] = 'Debe ser un entero positivo';
            }
        }
        if (isset($data['aviso_dias_previos']) && $data['aviso_dias_previos'] !== '' && $data['aviso_dias_previos'] !== null) {
            if (!is_numeric($data['aviso_dias_previos']) || (int) $data['aviso_dias_previos'] < 0) {
                $errors['aviso_dias_previos'] = 'Debe ser >= 0';
            }
        }

        // Anti-script en descripcion/observaciones
        foreach (['descripcion', 'observaciones'] as $f) {
            if (isset($data[$f]) && is_string($data[$f])
                && preg_match('/<\s*(script|iframe|object|embed)\b/i', $data[$f])) {
                $errors[$f] = 'Contenido no permitido';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Datos inválidos', $errors);
        }

        return self::normalize($data);
    }

    /**
     * Para update: igual que create pero todos los campos opcionales (parcial).
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function validateUpdate(array $data): array
    {
        $errors = [];

        if (array_key_exists('nombre', $data)) {
            $nombre = trim((string) $data['nombre']);
            if ($nombre === '' || mb_strlen($nombre) > 200) {
                $errors['nombre'] = 'Requerido, hasta 200 caracteres';
            }
        }

        if (array_key_exists('importe_base', $data)
            && (!is_numeric($data['importe_base']) || (float) $data['importe_base'] <= 0)) {
            $errors['importe_base'] = 'Debe ser numérico y > 0';
        }

        foreach (['fecha_inicio', 'fecha_fin'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null && $data[$f] !== '' && !self::isDate((string) $data[$f])) {
                $errors[$f] = 'Fecha inválida';
            }
        }

        if (array_key_exists('dia_facturacion', $data) && $data['dia_facturacion'] !== null && $data['dia_facturacion'] !== '') {
            $d = (int) $data['dia_facturacion'];
            if ($d < 1 || $d > 31) {
                $errors['dia_facturacion'] = 'Debe estar entre 1 y 31';
            }
        }
        if (array_key_exists('intervalo_dias', $data) && $data['intervalo_dias'] !== null && $data['intervalo_dias'] !== '') {
            if (!is_numeric($data['intervalo_dias']) || (int) $data['intervalo_dias'] < 1) {
                $errors['intervalo_dias'] = 'Debe ser entero positivo';
            }
        }

        if (array_key_exists('frecuencia_ajuste_meses', $data) && $data['frecuencia_ajuste_meses'] !== null && $data['frecuencia_ajuste_meses'] !== '') {
            if (!is_numeric($data['frecuencia_ajuste_meses']) || (int) $data['frecuencia_ajuste_meses'] < 1) {
                $errors['frecuencia_ajuste_meses'] = 'Debe ser entero positivo';
            }
        }

        // Campos PROHIBIDOS de cambiar en update
        $prohibidos = ['cliente_id', 'tipo', 'moneda'];
        foreach ($prohibidos as $f) {
            if (array_key_exists($f, $data)) {
                $errors[$f] = 'No se puede modificar después de crear el servicio';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException('Datos inválidos', $errors);
        }

        $clean = [];
        $stringFields = ['nombre', 'descripcion', 'observaciones'];
        foreach ($stringFields as $f) {
            if (array_key_exists($f, $data)) {
                $v = is_string($data[$f]) ? trim($data[$f]) : $data[$f];
                $clean[$f] = ($v === '' ? null : $v);
            }
        }
        if (array_key_exists('importe_base', $data)) {
            $clean['importe_base'] = (float) $data['importe_base'];
        }
        foreach (['fecha_inicio', 'fecha_fin'] as $f) {
            if (array_key_exists($f, $data)) {
                $clean[$f] = ($data[$f] === '' || $data[$f] === null) ? null : (string) $data[$f];
            }
        }
        foreach (['dia_facturacion', 'intervalo_dias', 'frecuencia_ajuste_meses', 'aviso_dias_previos'] as $f) {
            if (array_key_exists($f, $data)) {
                $clean[$f] = ($data[$f] === '' || $data[$f] === null) ? null : (int) $data[$f];
            }
        }
        return $clean;
    }

    // ============================================================
    // PRIVADOS
    // ============================================================

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $errors
     */
    private static function validateProyecto(array $data, array &$errors): void
    {
        if (empty($data['fecha_fin'])) {
            $errors['fecha_fin'] = 'Requerida para proyectos';
        }

        $camposProhibidos = ['modo_facturacion', 'dia_facturacion', 'intervalo_dias'];
        foreach ($camposProhibidos as $f) {
            if (!empty($data[$f])) {
                $errors[$f] = 'No aplica para proyectos';
            }
        }

        $cuotas = $data['cuotas'] ?? null;
        if (!is_array($cuotas) || count($cuotas) < 1) {
            $errors['cuotas'] = 'Requeridas para proyectos (al menos 1)';
            return;
        }

        $sumaPorcentajes = 0.0;
        foreach ($cuotas as $i => $c) {
            if (!is_array($c)) {
                $errors["cuotas.{$i}"] = 'Debe ser un objeto';
                continue;
            }
            if (!isset($c['porcentaje']) || !is_numeric($c['porcentaje']) || (float) $c['porcentaje'] <= 0 || (float) $c['porcentaje'] > 100) {
                $errors["cuotas.{$i}.porcentaje"] = 'Debe ser un número entre 0 (exclusivo) y 100';
            } else {
                $sumaPorcentajes += (float) $c['porcentaje'];
            }
            if (empty($c['fecha_prevista']) || !self::isDate((string) $c['fecha_prevista'])) {
                $errors["cuotas.{$i}.fecha_prevista"] = 'Fecha inválida';
            }
            if (isset($c['etiqueta']) && mb_strlen((string) $c['etiqueta']) > 100) {
                $errors["cuotas.{$i}.etiqueta"] = 'Hasta 100 caracteres';
            }
        }

        if (abs($sumaPorcentajes - 100.0) > self::PORCENTAJE_TOLERANCIA) {
            $errors['cuotas'] = sprintf(
                'Los porcentajes deben sumar 100 (suma actual: %.2f)',
                $sumaPorcentajes,
            );
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $errors
     */
    private static function validateMantenimiento(array $data, array &$errors): void
    {
        $modo = (string) ($data['modo_facturacion'] ?? '');
        if (!in_array($modo, Servicio::MODOS_FACTURACION, true)) {
            $errors['modo_facturacion'] = 'Requerido. Valores: ' . implode(', ', Servicio::MODOS_FACTURACION);
            return;
        }

        if ($modo === Servicio::MODO_MES_CALENDARIO) {
            if (empty($data['dia_facturacion'])) {
                $errors['dia_facturacion'] = 'Requerido en modo mes_calendario (1-31)';
            } else {
                $d = (int) $data['dia_facturacion'];
                if ($d < 1 || $d > 31) {
                    $errors['dia_facturacion'] = 'Debe estar entre 1 y 31';
                }
            }
            if (!empty($data['intervalo_dias'])) {
                $errors['intervalo_dias'] = 'No aplica en modo mes_calendario';
            }
        } else {
            if (empty($data['intervalo_dias']) || (int) $data['intervalo_dias'] < 1) {
                $errors['intervalo_dias'] = 'Requerido en modo intervalo_dias (entero >= 1)';
            }
            if (!empty($data['dia_facturacion'])) {
                $errors['dia_facturacion'] = 'No aplica en modo intervalo_dias';
            }
        }

        if (!empty($data['cuotas'])) {
            $errors['cuotas'] = 'No se ingresan manualmente en mantenimiento (se generan automáticamente)';
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{servicio: array<string,mixed>, cuotas: array<int, array<string,mixed>>}
     */
    private static function normalize(array $data): array
    {
        $tipo = $data['tipo'];
        $servicio = [
            'cliente_id' => (int) $data['cliente_id'],
            'tipo' => $tipo,
            'nombre' => trim((string) $data['nombre']),
            'descripcion' => isset($data['descripcion']) && trim((string) $data['descripcion']) !== ''
                ? trim((string) $data['descripcion']) : null,
            'moneda' => $data['moneda'] ?? 'ARS',
            'importe_base' => (float) $data['importe_base'],
            'fecha_inicio' => (string) $data['fecha_inicio'],
            'fecha_fin' => !empty($data['fecha_fin']) ? (string) $data['fecha_fin'] : null,
            'observaciones' => isset($data['observaciones']) && trim((string) $data['observaciones']) !== ''
                ? trim((string) $data['observaciones']) : null,
        ];

        if ($tipo === Servicio::TIPO_MANTENIMIENTO) {
            $servicio['modo_facturacion'] = $data['modo_facturacion'];
            $servicio['dia_facturacion'] = !empty($data['dia_facturacion']) ? (int) $data['dia_facturacion'] : null;
            $servicio['intervalo_dias'] = !empty($data['intervalo_dias']) ? (int) $data['intervalo_dias'] : null;
            $servicio['frecuencia_ajuste_meses'] = !empty($data['frecuencia_ajuste_meses'])
                ? (int) $data['frecuencia_ajuste_meses'] : null;
            $servicio['aviso_dias_previos'] = !empty($data['aviso_dias_previos'])
                ? (int) $data['aviso_dias_previos'] : null;
        } else {
            // Proyectos: campos de mantenimiento siempre NULL
            $servicio['modo_facturacion'] = null;
            $servicio['dia_facturacion'] = null;
            $servicio['intervalo_dias'] = null;
            $servicio['frecuencia_ajuste_meses'] = null;
            $servicio['aviso_dias_previos'] = null;
        }

        $cuotas = [];
        if ($tipo === Servicio::TIPO_PROYECTO && isset($data['cuotas']) && is_array($data['cuotas'])) {
            foreach ($data['cuotas'] as $c) {
                $cuotas[] = [
                    'porcentaje' => (float) $c['porcentaje'],
                    'fecha_prevista' => (string) $c['fecha_prevista'],
                    'etiqueta' => isset($c['etiqueta']) ? trim((string) $c['etiqueta']) : null,
                ];
            }
        }

        return ['servicio' => $servicio, 'cuotas' => $cuotas];
    }

    private static function isDate(string $value): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($d === false) {
            return false;
        }
        $year = (int) $d->format('Y');
        return $year >= 1900 && $year <= 2100;
    }
}
