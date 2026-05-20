<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $razon_social
 * @property string $cuit
 * @property string|null $cuit_pais
 * @property string|null $tipo_default
 * @property string|null $direccion
 * @property string|null $banco
 * @property string|null $cbu
 * @property string|null $alias
 * @property int|null $plazo_pago_default
 * @property string|null $mail_envio_factura
 * @property string|null $contacto_envio_factura
 * @property string|null $telefono_contacto_proveedores
 * @property string|null $mail_gestion_cobranza
 * @property string|null $contacto_gestion_cobranza
 * @property string|null $telefono_contacto_cobranza
 * @property string|null $observaciones
 * @property bool $activo
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 * @property \DateTimeInterface|null $deleted_at
 */
final class Cliente extends Model
{
    use SoftDeletes;

    protected $table = 'clientes';

    protected $fillable = [
        'razon_social',
        'cuit',
        'cuit_pais',
        'tipo_default',
        'direccion',
        'banco',
        'cbu',
        'alias',
        'plazo_pago_default',
        'mail_envio_factura',
        'contacto_envio_factura',
        'telefono_contacto_proveedores',
        'mail_gestion_cobranza',
        'contacto_gestion_cobranza',
        'telefono_contacto_cobranza',
        'observaciones',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'plazo_pago_default' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function facturas(): HasMany
    {
        return $this->hasMany(FacturaVenta::class, 'cliente_id');
    }
}
