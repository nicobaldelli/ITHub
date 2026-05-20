<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash       SHA-256 hex (64 chars)
 * @property string $family_id        UUID que agrupa la cadena de rotaciones
 * @property \DateTimeInterface $expires_at
 * @property \DateTimeInterface|null $revoked_at
 * @property int|null $replaced_by_id
 * @property string|null $user_agent
 * @property string|null $ip
 * @property \DateTimeInterface $created_at
 */
final class RefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    public $timestamps = false; // solo created_at, no updated_at

    protected $fillable = [
        'user_id',
        'token_hash',
        'family_id',
        'expires_at',
        'revoked_at',
        'replaced_by_id',
        'user_agent',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'replaced_by_id' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->getTimestamp() < time();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isUsable(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }
}
