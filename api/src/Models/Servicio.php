<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $cliente_id
 * @property string $tipo                     proyecto | mantenimiento
 * @property string $nombre
 * @property string|null $descripcion
 * @property string $moneda                   ARS | USD
 * @property string $importe_base             total para proyecto / por cuota para mantenimiento
 * @property \DateTimeInterface $fecha_inicio
 * @property \DateTimeInterface|null $fecha_fin (null = indefinido)
 * @property string|null $modo_facturacion    mes_calendario | intervalo_dias
 * @property int|null $dia_facturacion        1-31 (modo mes_calendario)
 * @property int|null $intervalo_dias         intervalo en días (modo intervalo_dias)
 * @property int|null $frecuencia_ajuste_meses
 * @property int|null $aviso_dias_previos
 * @property string $estado                   activo | pausado | completado | cancelado
 * @property \DateTimeInterface|null $pausado_at
 * @property string|null $observaciones
 * @property int $created_by
 * @property int $updated_by
 */
final class Servicio extends Model
{
    use SoftDeletes;

    public const TIPO_PROYECTO = 'proyecto';
    public const TIPO_MANTENIMIENTO = 'mantenimiento';
    public const TIPOS = [self::TIPO_PROYECTO, self::TIPO_MANTENIMIENTO];

    public const MODO_MES_CALENDARIO = 'mes_calendario';
    public const MODO_INTERVALO_DIAS = 'intervalo_dias';
    public const MODOS_FACTURACION = [self::MODO_MES_CALENDARIO, self::MODO_INTERVALO_DIAS];

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_PAUSADO = 'pausado';
    public const ESTADO_COMPLETADO = 'completado';
    public const ESTADO_CANCELADO = 'cancelado';
    public const ESTADOS = [
        self::ESTADO_ACTIVO,
        self::ESTADO_PAUSADO,
        self::ESTADO_COMPLETADO,
        self::ESTADO_CANCELADO,
    ];

    public const MONEDAS = ['ARS', 'USD'];

    protected $table = 'servicios';

    protected $fillable = [
        'cliente_id',
        'tipo',
        'nombre',
        'descripcion',
        'moneda',
        'importe_base',
        'iva_porcentaje',
        'template_factura',
        'tipo_factura_default',
        'fecha_inicio',
        'fecha_fin',
        'modo_facturacion',
        'dia_facturacion',
        'intervalo_dias',
        'frecuencia_ajuste_meses',
        'aviso_dias_previos',
        'estado',
        'pausado_at',
        'observaciones',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'cliente_id' => 'integer',
        'importe_base' => 'decimal:2',
        'iva_porcentaje' => 'decimal:2',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'dia_facturacion' => 'integer',
        'intervalo_dias' => 'integer',
        'frecuencia_ajuste_meses' => 'integer',
        'aviso_dias_previos' => 'integer',
        'pausado_at' => 'datetime',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'cliente_id');
    }

    public function cuotas(): HasMany
    {
        return $this->hasMany(ServicioCuota::class, 'servicio_id')->orderBy('numero_cuota');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(ServicioAjuste::class, 'servicio_id')->orderByDesc('fecha_aplicacion');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ============================================================
    // Helpers de tipo / estado
    // ============================================================

    public function esProyecto(): bool
    {
        return $this->tipo === self::TIPO_PROYECTO;
    }

    public function esMantenimiento(): bool
    {
        return $this->tipo === self::TIPO_MANTENIMIENTO;
    }

    public function esIndefinido(): bool
    {
        return $this->esMantenimiento() && $this->fecha_fin === null;
    }

    public function estaActivo(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }
}
