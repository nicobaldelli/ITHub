<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabla de configuración key-value.
 *
 * @property string $clave
 * @property string|null $valor
 * @property string $tipo       string|int|bool|json
 * @property string|null $descripcion
 * @property int|null $updated_by
 * @property \DateTimeInterface $updated_at
 */
final class ConfigApp extends Model
{
    protected $table = 'config_app';
    protected $primaryKey = 'clave';
    public $incrementing = false;
    protected $keyType = 'string';

    public const CREATED_AT = null; // solo updated_at

    protected $fillable = ['clave', 'valor', 'tipo', 'descripcion', 'updated_by'];

    protected $casts = [
        'updated_by' => 'integer',
        'updated_at' => 'datetime',
    ];

    /**
     * Devuelve el valor casteado al tipo correcto.
     */
    public function getValueAttribute(): mixed
    {
        if ($this->valor === null) {
            return null;
        }
        return match ($this->tipo) {
            'int' => (int) $this->valor,
            'bool' => filter_var($this->valor, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($this->valor, true),
            default => $this->valor,
        };
    }
}
