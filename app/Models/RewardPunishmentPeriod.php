<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardPunishmentPeriod extends Model
{
    protected $table = 'reward_punishment_period';

    public $timestamps = true;

    protected $fillable = [
        'nama_periode',
        'tanggal_mulai',
        'tanggal_selesai',
        'target_bulan_berikutnya',
        'status',
        'catatan',
        'keterangan',
        'created_by',
        'finalized_by',
        'finalized_at',
    ];

    protected $casts = [
        'tanggal_mulai' => 'date:Y-m-d',
        'tanggal_selesai' => 'date:Y-m-d',
        'finalized_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function caterings(): HasMany
    {
        return $this->hasMany(RewardPunishmentCatering::class, 'period_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(RewardPunishmentShift::class, 'period_id');
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
