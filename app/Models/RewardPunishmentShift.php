<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardPunishmentShift extends Model
{
    protected $table = 'reward_punishment_shift';

    public $timestamps = false;

    protected $fillable = [
        'period_id',
        'group_code',
        'shift',
        'jumlah_order_total',
        'jumlah_karyawan',
    ];

    protected $casts = [
        'jumlah_order_total' => 'float',
        'jumlah_karyawan' => 'float',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(RewardPunishmentPeriod::class, 'period_id');
    }
}
