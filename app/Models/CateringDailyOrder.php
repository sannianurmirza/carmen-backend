<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CateringDailyOrder extends Model
{
    protected $table = 'catering_daily_order';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'tanggal',
        'shift',
        'jumlah_order',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'jumlah_order' => 'integer',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
