<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $numero_factura
 * @property int $cliente_id
 * @property string $tipo
 * @property string $cuit
 * @property string|null $cuit_pais
 * @property string $moneda
 * @property float $importe_sin_iva
 * @property float $importe_con_iva
 * @property float $importe_total_pesos
 * @property float|null $tdc
 * @property float $retenciones
 * @property float $total_cobrado
 * @property string|null $detalle_factura
 * @property int|null $numero_mes
 * @property string|null $mes_cubierto
 * @property \DateTimeInterface $fecha_factura
 * @property \DateTimeInterface|null $fecha_envio
 * @property string|null $banco
 * @property \DateTimeInterface|null $vencimiento
 * @property string|null $cbu
 * @property string|null $alias
 * @property int|null $plazo_pago
 * @property \DateTimeInterface|null $fecha_pago
 * @property string|null $direccion
 * @property string|null $mail_envio_factura
 * @property string|null $contacto_envio_factura
 * @property string|null $telefono_contacto_proveedores
 * @property string|null $mail_gestion_cobranza
 * @property string|null $contacto_gestion_cobranza
 * @property string|null $telefono_contacto_cobranza
 * @property string|null $observaciones
 * @property bool $check_cobranza
 * @property int|null $check_cobranza_user_id
 * @property \DateTimeInterface|null $check_cobranza_fecha
 * @property string|null $drive_folder_id
 * @property string $estado
 * @property int $created_by
 * @property int $updated_by
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 * @property \DateTimeInterface|null $deleted_at
 */
final class FacturaVenta extends Model
{
    use SoftDeletes;

    public const TIPOS = [
        'A', 'B', 'E',
        'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B',
        'NC_A', 'NC_B', 'NC_E',
        'ND_A', 'ND_B', 'ND_E',
    ];

    public const MONEDAS = ['ARS', 'USD'];

    public const ESTADO_BORRADOR = 'borrador';
    public const ESTADO_EMITIDA = 'emitida';
    public const ESTADO_COBRADA = 'cobrada';
    public const ESTADO_VENCIDA = 'vencida';
    public const ESTADO_ANULADA = 'anulada';

    public const ESTADOS = [
        self::ESTADO_BORRADOR,
        self::ESTADO_EMITIDA,
        self::ESTADO_COBRADA,
        self::ESTADO_VENCIDA,
        self::ESTADO_ANULADA,
    ];

    protected $table = 'facturas_venta';

    protected $fillable = [
        'numero_factura',
        'cliente_id',
        'tipo',
        'cuit',
        'cuit_pais',
        'moneda',
        'importe_sin_iva',
        'importe_con_iva',
        'importe_total_pesos',
        'tdc',
        'retenciones',
        'total_cobrado',
        'detalle_factura',
        'numero_mes',
        'mes_cubierto',
        'fecha_factura',
        'fecha_envio',
        'banco',
        'vencimiento',
        'cbu',
        'alias',
        'plazo_pago',
        'fecha_pago',
        'direccion',
        'mail_envio_factura',
        'contacto_envio_factura',
        'telefono_contacto_proveedores',
        'mail_gestion_cobranza',
        'contacto_gestion_cobranza',
        'telefono_contacto_cobranza',
        'observaciones',
        'check_cobranza',
        'check_cobranza_user_id',
        'check_cobranza_fecha',
        'drive_folder_id',
        'estado',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'cliente_id' => 'integer',
        'importe_sin_iva' => 'decimal:2',
        'importe_con_iva' => 'decimal:2',
        'importe_total_pesos' => 'decimal:2',
        'tdc' => 'decimal:4',
        'retenciones' => 'decimal:2',
        'total_cobrado' => 'decimal:2',
        'numero_mes' => 'integer',
        'plazo_pago' => 'integer',
        'fecha_factura' => 'date',
        'fecha_envio' => 'date',
        'vencimiento' => 'date',
        'fecha_pago' => 'date',
        'check_cobranza' => 'boolean',
        'check_cobranza_user_id' => 'integer',
        'check_cobranza_fecha' => 'datetime',
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

    public function checkCobranzaUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'check_cobranza_user_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(FacturaArchivo::class, 'factura_id');
    }
}
