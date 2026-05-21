<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de cambios de tarifa de un servicio de mantenimiento.
 *
 * Estados:
 *  - aplicado = false → pendiente de aplicar (programado a futuro o sin ejecutar)
 *  - aplicado = true  → ya impactó las cuotas futuras
 *
 * @property int $id
 * @property int $servicio_id
 * @property string $tipo                 programado | espontaneo
 * @property \DateTimeInterface $fecha_aplicacion
 * @property int|null $cuota_desde_id
 * @property string $importe_anterior
 * @property string $importe_nuevo
 * @property string|null $porcentaje_variacion
 * @property bool $aplicado
 * @property \DateTimeInterface|null $aplicado_at
 * @property int|null $aplicado_por
 * @property string|null $observaciones
 * @property int $created_by
 */
final class ServicioAjuste extends Model
{
    public const TIPO_PROGRAMADO = 'programado';
    public const TIPO_ESPONTANEO = 'espontaneo';
    public const TIPOS = [self::TIPO_PROGRAMADO, self::TIPO_ESPONTANEO];

    protected $table = 'servicio_ajustes';

    protected $fillable = [
        'servicio_id',
        'tipo',
        'fecha_aplicacion',
        'cuota_desde_id',
        'importe_anterior',
        'importe_nuevo',
        'porcentaje_variacion',
        'aplicado',
        'aplicado_at',
        'aplicado_por',
        'observaciones',
        'created_by',
    ];

    protected $casts = [
        'servicio_id' => 'integer',
        'fecha_aplicacion' => 'date',
        'cuota_desde_id' => 'integer',
        'importe_anterior' => 'decimal:2',
        'importe_nuevo' => 'decimal:2',
        'porcentaje_variacion' => 'decimal:4',
        'aplicado' => 'boolean',
        'aplicado_at' => 'datetime',
        'aplicado_por' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function cuotaDesde(): BelongsTo
    {
        return $this->belongsTo(ServicioCuota::class, 'cuota_desde_id');
    }

    public function aplicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aplicado_por');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
