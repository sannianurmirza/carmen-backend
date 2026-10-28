<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaporanPerformanceBulanan extends Model
{
    protected $table = 'laporan_performance_bulanan';

    public $timestamps = true;

    protected $fillable = [
        'bulan_laporan',
        'tahun_laporan',
        'periode_mulai',
        'periode_selesai',
        'nama_periode',
        'tanggal_dibuat',
        'status',
        'rule_version',
        'summary_snapshot',
        'total_caterings',
        'notes',
        'created_by',
        'finalized_by',
        'finalized_at',
    ];

    protected $casts = [
        'bulan_laporan' => 'integer',
        'tahun_laporan' => 'integer',
        'periode_mulai' => 'date:Y-m-d',
        'periode_selesai' => 'date:Y-m-d',
        'tanggal_dibuat' => 'date:Y-m-d',
        'total_caterings' => 'integer',
        'finalized_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(LaporanPerformanceItem::class, 'laporan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
