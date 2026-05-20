<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $entidad
 * @property int|null $entidad_id
 * @property string $accion
 * @property array|null $campos_modificados
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property \DateTimeInterface $created_at
 */
final class Auditoria extends Model
{
    public const ACCION_CREAR = 'crear';
    public const ACCION_EDITAR = 'editar';
    public const ACCION_ELIMINAR = 'eliminar';
    public const ACCION_MARCAR_COBRADA = 'marcar_cobrada';
    public const ACCION_LOGIN = 'login';
    public const ACCION_LOGIN_FALLIDO = 'login_fallido';
    public const ACCION_LOGOUT = 'logout';
    public const ACCION_EXPORT = 'export';
    public const ACCION_IMPORT = 'import';
    public const ACCION_ARCHIVO_SUBIDO = 'archivo_subido';
    public const ACCION_ARCHIVO_ELIMINADO = 'archivo_eliminado';
    public const ACCION_CONFIG_ACTUALIZADA = 'config_actualizada';
    public const ACCION_CAMBIO_PASSWORD = 'cambio_password';
    public const ACCION_RESET_PASSWORD = 'reset_password';

    protected $table = 'auditoria';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'entidad',
        'entidad_id',
        'accion',
        'campos_modificados',
        'ip',
        'user_agent',
        'request_id',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'entidad_id' => 'integer',
        'campos_modificados' => 'array', // JSON ↔ array automático
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
