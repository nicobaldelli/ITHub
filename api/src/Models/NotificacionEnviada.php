<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $tipo                   vencimiento_proximo | vencida | ajuste_proximo
 * @property string $entidad
 * @property int $entidad_id
 * @property int|null $dias_ref
 * @property array|null $destinatarios
 * @property bool $ok
 * @property string|null $error_msg
 * @property \DateTimeInterface $created_at
 */
final class NotificacionEnviada extends Model
{
    public const TIPO_VENCIMIENTO_PROXIMO = 'vencimiento_proximo';
    public const TIPO_VENCIDA = 'vencida';
    public const TIPO_AJUSTE_PROXIMO = 'ajuste_proximo';

    protected $table = 'notificaciones_enviadas';

    public $timestamps = false;

    protected $fillable = [
        'tipo',
        'entidad',
        'entidad_id',
        'dias_ref',
        'destinatarios',
        'ok',
        'error_msg',
        'created_at',
    ];

    protected $casts = [
        'entidad_id' => 'integer',
        'dias_ref' => 'integer',
        'destinatarios' => 'array',
        'ok' => 'boolean',
        'created_at' => 'datetime',
    ];
}
