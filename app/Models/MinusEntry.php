<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinusEntry extends Model
{
    protected $table = 'minusentry';

    public $timestamps = true;

    protected $fillable = [
        'kalori_harian_id',
        'catering_id',
        'tanggal',
        'shift',
        'menu',
        'foto_url',
        'hasil_kalori',
        'problem',
        'perbaikan',
        'is_active',
        'status_pengisian',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'hasil_kalori' => 'float',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function setTanggalAttribute($value): void
    {
        $this->attributes['tanggal'] = $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : substr(trim((string) $value), 0, 10);
    }

    public function catering(): BelongsTo
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }

    public function kaloriHarian(): BelongsTo
    {
        return $this->belongsTo(KaloriHarian::class, 'kalori_harian_id');
    }
}
