<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaporanPerformanceItem extends Model
{
    protected $table = 'laporan_performance_item';

    public $timestamps = false;

    protected $fillable = [
        'laporan_id',
        'catering_id',
        'score_terlambat',
        'score_kelayakan',
        'score_kalori',
        'score_komplain',
        'score_audit',
        'score_kebersihan',
        'kebersihan_kondisi',
        'subtotal_5_komponen',
        'total_score',
        'grade',
        'action',
        'score_bulan_lalu',
        'grade_bulan_lalu',
        'raw_data_json',
        'previous_data_json',
    ];

    protected $casts = [
        'score_terlambat' => 'float',
        'score_kelayakan' => 'float',
        'score_kalori' => 'float',
        'score_komplain' => 'float',
        'score_audit' => 'float',
        'score_kebersihan' => 'float',
        'subtotal_5_komponen' => 'float',
        'total_score' => 'float',
        'score_bulan_lalu' => 'float',
    ];

    public function laporan(): BelongsTo
    {
        return $this->belongsTo(LaporanPerformanceBulanan::class, 'laporan_id');
    }

    public function catering(): BelongsTo
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }
}
