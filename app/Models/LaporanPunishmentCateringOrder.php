<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaporanPunishmentCateringOrder extends Model
{
    protected $table = 'laporan_punishment_catering_order';

    public $timestamps = false;

    protected $fillable = [
        'laporan_id',
        'catering_id',
        'jumlah_order_pagi',
        'jumlah_order_malam',
        'kebersihan_score',
        'kebersihan_kondisi',
    ];

    protected $casts = [
        'jumlah_order_pagi' => 'float',
        'jumlah_order_malam' => 'float',
        'kebersihan_score' => 'float',
    ];

    public function laporan(): BelongsTo
    {
        return $this->belongsTo(LaporanPunishmentBulanan::class, 'laporan_id');
    }

    public function catering(): BelongsTo
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }
}
