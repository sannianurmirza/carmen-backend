<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KaloriHarianBahan extends Model
{
    protected $table = 'kaloriharianbahan';

    public $timestamps = false;

    protected $fillable = [
        'kalori_harian_id',
        'kategori',
        'item_role',
        'nama_bahan',
        'standar_kalori',
        'standar_berat',
        'berat_aktual',
        'preset_name',
    ];

    protected $casts = [
        'standar_kalori' => 'float',
        'standar_berat' => 'float',
        'berat_aktual' => 'float',
    ];

    public function kaloriHarian(): BelongsTo
    {
        return $this->belongsTo(KaloriHarian::class, 'kalori_harian_id');
    }

    public function getEnergiAttribute(): float
    {
        $stdK = (float)$this->standar_kalori;
        $stdB = $this->standar_berat !== null ? (float)$this->standar_berat : null;
        $aktB = (float)$this->berat_aktual;
        $weight = $stdB !== null ? $stdB : $aktB;
        return ($stdK / 100.0) * $weight;
    }
}

