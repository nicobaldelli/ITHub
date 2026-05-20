<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nombre
 * @property string $apellido
 * @property string $email
 * @property string $password_hash
 * @property string $rol             admin|cobranzas|ventas|visualizador
 * @property bool $activo
 * @property bool $must_change_password
 * @property int $failed_login_attempts
 * @property \DateTimeInterface|null $locked_until
 * @property \DateTimeInterface|null $last_login
 * @property string|null $last_login_ip
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 */
final class User extends Model
{
    public const ROL_ADMIN = 'admin';
    public const ROL_COBRANZAS = 'cobranzas';
    public const ROL_VENTAS = 'ventas';
    public const ROL_VISUALIZADOR = 'visualizador';

    public const ROLES = [
        self::ROL_ADMIN,
        self::ROL_COBRANZAS,
        self::ROL_VENTAS,
        self::ROL_VISUALIZADOR,
    ];

    protected $table = 'users';

    /**
     * Mass-assignment whitelist explícita.
     * `password_hash` se setea siempre vía mutator dedicado, nunca por fill.
     */
    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'rol',
        'activo',
        'must_change_password',
    ];

    /**
     * Campos que NUNCA se serializan al cliente.
     */
    protected $hidden = [
        'password_hash',
        'failed_login_attempts',
        'locked_until',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'must_change_password' => 'boolean',
        'failed_login_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_login' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class, 'user_id');
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->getTimestamp() > time();
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombre . ' ' . $this->apellido);
    }
}
