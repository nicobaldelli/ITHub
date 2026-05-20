<?php

declare(strict_types=1);

namespace ITHub\Api\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $factura_id
 * @property string $drive_file_id
 * @property string $nombre_archivo
 * @property string|null $mime_type
 * @property int|null $tamanio_bytes
 * @property string|null $drive_view_url
 * @property string|null $drive_download_url
 * @property int $uploaded_by
 * @property \DateTimeInterface $created_at
 */
final class FacturaArchivo extends Model
{
    protected $table = 'factura_archivos';

    public $timestamps = false;

    protected $fillable = [
        'factura_id',
        'drive_file_id',
        'nombre_archivo',
        'mime_type',
        'tamanio_bytes',
        'drive_view_url',
        'drive_download_url',
        'uploaded_by',
        'created_at',
    ];

    protected $casts = [
        'factura_id' => 'integer',
        'tamanio_bytes' => 'integer',
        'uploaded_by' => 'integer',
        'created_at' => 'datetime',
    ];

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaVenta::class, 'factura_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
