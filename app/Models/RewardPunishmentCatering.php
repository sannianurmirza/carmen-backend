<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardPunishmentCatering extends Model
{
    protected $table = 'reward_punishment_catering';

    public $timestamps = false;

    protected $fillable = [
        'period_id',
        'catering_id',
        'catering_nama_snapshot',
        'group_code',
        'shift',
        'jumlah_order_berjalan',
        'prosentase_order',
        'performa_katering',
        'sanksi_terlambat',
        'sanksi_kelayakan',
        'sanksi_kalori',
        'sanksi_komplain',
        'sanksi_audit',
        'sanksi_kebersihan',
        'total_sanksi',
        'reward',
        'hasil_perhitungan',
        'prosentase_order_berikutnya',
        'perkiraan_order',
        'order_minmax',
        'aktual_order',
        'catatan',
    ];

    protected $casts = [
        'jumlah_order_berjalan' => 'float',
        'prosentase_order' => 'float',
        'performa_katering' => 'float',
        'sanksi_terlambat' => 'float',
        'sanksi_kelayakan' => 'float',
        'sanksi_kalori' => 'float',
        'sanksi_komplain' => 'float',
        'sanksi_audit' => 'float',
        'sanksi_kebersihan' => 'float',
        'total_sanksi' => 'float',
        'reward' => 'float',
        'hasil_perhitungan' => 'float',
        'prosentase_order_berikutnya' => 'float',
        'perkiraan_order' => 'float',
        'order_minmax' => 'float',
        'aktual_order' => 'float',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(RewardPunishmentPeriod::class, 'period_id');
    }

    public function catering(): BelongsTo
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }
}
