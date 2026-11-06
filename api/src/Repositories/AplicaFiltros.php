<?php

declare(strict_types=1);

namespace ITHub\Api\Repositories;

use Illuminate\Database\Eloquent\Builder;

/**
 * Helpers compartidos por los repositorios para traducir query params
 * (strings crudos) en condiciones seguras del query builder.
 *
 * Todo lo que no se puede interpretar (fecha inválida, número no numérico,
 * valor fuera del catálogo) se ignora en silencio: un filtro mal formado no
 * tiene que tirar abajo el listado ni filtrar de más.
 */
trait AplicaFiltros
{
    /** @param array<string,mixed> $filters */
    private function lleno(array $filters, string $key): bool
    {
        if (!array_key_exists($key, $filters)) {
            return false;
        }
        $v = $filters[$key];
        return $v !== null && $v !== '' && !is_array($v);
    }

    /** Fecha en formato Y-m-d o null si no es válida. */
    private function fechaValida(mixed $valor): ?string
    {
        if (!is_string($valor) && !is_numeric($valor)) {
            return null;
        }
        $s = trim((string) $valor);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y) ? $s : null;
    }

    private function numeroValido(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        if (!is_string($valor)) {
            return null;
        }
        // Acepta "1.234,56" (formato AR) y "1234.56"
        $s = trim($valor);
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    /** Patrón LIKE con los comodines del usuario escapados. */
    private function like(mixed $valor): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim((string) $valor)) . '%';
    }

    /** @param array<string,mixed> $filters */
    private function igual(Builder $q, array $filters, string $key, string $columna, ?array $catalogo = null): void
    {
        if (!$this->lleno($filters, $key)) {
            return;
        }
        $v = (string) $filters[$key];
        if ($catalogo !== null && !in_array($v, $catalogo, true)) {
            return;
        }
        $q->where($columna, $v);
    }

    /** @param array<string,mixed> $filters */
    private function entero(Builder $q, array $filters, string $key, string $columna): void
    {
        if (!$this->lleno($filters, $key) || !is_numeric($filters[$key])) {
            return;
        }
        $q->where($columna, (int) $filters[$key]);
    }

    /** @param array<string,mixed> $filters */
    private function contiene(Builder $q, array $filters, string $key, string $columna): void
    {
        if (!$this->lleno($filters, $key)) {
            return;
        }
        $q->where($columna, 'like', $this->like($filters[$key]));
    }

    /** @param array<string,mixed> $filters */
    private function booleano(Builder $q, array $filters, string $key, string $columna): void
    {
        if (!$this->lleno($filters, $key)) {
            return;
        }
        $bool = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            return;
        }
        $q->where($columna, $bool);
    }

    /**
     * Filtro booleano de "tiene / no tiene" con la condición delegada.
     *
     * @param array<string,mixed> $filters
     * @param callable(Builder,bool):mixed $aplicar
     */
    private function presencia(Builder $q, array $filters, string $key, callable $aplicar): void
    {
        if (!$this->lleno($filters, $key)) {
            return;
        }
        $bool = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            return;
        }
        $aplicar($q, $bool);
    }

    /**
     * Rango de fechas inclusivo sobre una columna date/datetime.
     * @param array<string,mixed> $filters
     */
    private function rangoFecha(
        Builder $q,
        array $filters,
        string $columna,
        string $desdeKey,
        string $hastaKey,
        bool $esDatetime = false,
    ): void {
        if ($this->lleno($filters, $desdeKey) && ($d = $this->fechaValida($filters[$desdeKey])) !== null) {
            $q->where($columna, '>=', $esDatetime ? $d . ' 00:00:00' : $d);
        }
        if ($this->lleno($filters, $hastaKey) && ($h = $this->fechaValida($filters[$hastaKey])) !== null) {
            $q->where($columna, '<=', $esDatetime ? $h . ' 23:59:59' : $h);
        }
    }

    /**
     * Rango numérico inclusivo.
     * @param array<string,mixed> $filters
     */
    private function rangoNumero(Builder $q, array $filters, string $columna, string $minKey, string $maxKey): void
    {
        if ($this->lleno($filters, $minKey) && ($min = $this->numeroValido($filters[$minKey])) !== null) {
            $q->where($columna, '>=', $min);
        }
        if ($this->lleno($filters, $maxKey) && ($max = $this->numeroValido($filters[$maxKey])) !== null) {
            $q->where($columna, '<=', $max);
        }
    }

    /**
     * Devuelve [columna, dirección] saneados para orderBy.
     * @param array<string,mixed> $filters
     * @param string[] $permitidas
     * @return array{0:string,1:string}
     */
    private function orden(array $filters, array $permitidas, string $default, string $dirDefault): array
    {
        $sortBy = (string) ($filters['sort_by'] ?? $default);
        if (!in_array($sortBy, $permitidas, true)) {
            $sortBy = $default;
        }
        $dirRaw = strtolower((string) ($filters['sort_dir'] ?? $dirDefault));
        $sortDir = in_array($dirRaw, ['asc', 'desc'], true) ? $dirRaw : $dirDefault;
        return [$sortBy, $sortDir];
    }
}
