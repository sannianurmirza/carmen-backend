<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class KaloriHarian extends Model
{
    protected $table = 'kaloriharian';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'tanggal',
        'shift',
        'jenis_masakan',
        'nama_masakan_nasi',
        'nama_masakan_sayur',
        'foto_url',
        'menu_components',
        'menu_plan_id',
        'menu_plan_version',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
        'menu_plan_id' => 'integer',
        'menu_plan_version' => 'integer',
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

    public function menuPlan(): BelongsTo
    {
        return $this->belongsTo(MonthlyMenuPlan::class, 'menu_plan_id');
    }

    public function bahan(): HasMany
    {
        return $this->hasMany(KaloriHarianBahan::class, 'kalori_harian_id');
    }

    public function bahans(): HasMany
    {
        return $this->bahan();
    }

    public function minusEntry(): HasOne
    {
        return $this->hasOne(MinusEntry::class, 'kalori_harian_id');
    }
}

