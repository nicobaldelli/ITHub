<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sesión abierta con el ingreso de ITHub (una por familia de refresh tokens).
 *
 * @property string $family_id
 * @property int $user_id
 * @property string $sid              sid de la sesión de ITHub
 * @property string $sub              id de la persona en ITHub
 * @property string $id_token         para salir de ITHub sin confirmación
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface|null $cerrada_en  ITHub avisó el cierre
 */
final class IthubSesion extends Model
{
    protected $table = 'ithub_sesiones';
    protected $primaryKey = 'family_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'family_id',
        'user_id',
        'sid',
        'sub',
        'id_token',
        'created_at',
        'cerrada_en',
    ];

    protected $hidden = ['id_token'];

    protected $casts = [
        'user_id' => 'integer',
        'created_at' => 'datetime',
        'cerrada_en' => 'datetime',
    ];

    public function estaCerrada(): bool
    {
        return $this->cerrada_en !== null;
    }
}
