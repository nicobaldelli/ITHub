<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una cuota = una factura. El cronograma de un servicio está compuesto por cuotas.
 *
 * @property int $id
 * @property int $servicio_id
 * @property int $numero_cuota
 * @property int|null $total_cuotas        null para mantenimientos indefinidos
 * @property string|null $porcentaje       solo proyectos
 * @property string $importe               en la moneda del servicio
 * @property \DateTimeInterface $fecha_prevista
 * @property int|null $factura_id
 * @property string $estado                pendiente | facturada | omitida | cancelada
 * @property string|null $etiqueta
 * @property bool $es_proporcional
 * @property int|null $dias_cubiertos
 * @property string|null $observaciones
 */
final class ServicioCuota extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_FACTURADA = 'facturada';
    public const ESTADO_OMITIDA = 'omitida';
    public const ESTADO_CANCELADA = 'cancelada';
    public const ESTADOS = [
        self::ESTADO_PENDIENTE,
        self::ESTADO_FACTURADA,
        self::ESTADO_OMITIDA,
        self::ESTADO_CANCELADA,
    ];

    /** Estados que se consideran "resueltos" para auto-completar el servicio. */
    public const ESTADOS_RESUELTOS = [
        self::ESTADO_FACTURADA,
        self::ESTADO_OMITIDA,
        self::ESTADO_CANCELADA,
    ];

    protected $table = 'servicio_cuotas';

    protected $fillable = [
        'servicio_id',
        'numero_cuota',
        'total_cuotas',
        'porcentaje',
        'importe',
        'fecha_prevista',
        'factura_id',
        'estado',
        'etiqueta',
        'es_proporcional',
        'dias_cubiertos',
        'observaciones',
    ];

    protected $casts = [
        'servicio_id' => 'integer',
        'numero_cuota' => 'integer',
        'total_cuotas' => 'integer',
        'porcentaje' => 'decimal:2',
        'importe' => 'decimal:2',
        'fecha_prevista' => 'date',
        'factura_id' => 'integer',
        'es_proporcional' => 'boolean',
        'dias_cubiertos' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(Servicio::class, 'servicio_id');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    public function esEditable(): bool
    {
        // No se puede tocar una cuota ya facturada o cancelada
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    public function estaResuelta(): bool
    {
        return in_array($this->estado, self::ESTADOS_RESUELTOS, true);
    }
}
