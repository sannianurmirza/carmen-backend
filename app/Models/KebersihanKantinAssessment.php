<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KebersihanKantinAssessment extends Model
{
    protected $table = 'kebersihan_kantin_assessment';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'periode_mulai',
        'periode_selesai',
        'kondisi_kebersihan',
        'pelanggaran_rantang_tidak_langsung_masuk_mobil',
        'pelanggaran_buka_pilah_rantang_sisa_di_jai',
        'pelanggaran_memberi_makan_kucing',
        'catatan',
        'status',
        'created_by',
        'finalized_by',
        'finalized_at',
    ];

    protected $casts = [
        'periode_mulai' => 'date:Y-m-d',
        'periode_selesai' => 'date:Y-m-d',
        'pelanggaran_rantang_tidak_langsung_masuk_mobil' => 'boolean',
        'pelanggaran_buka_pilah_rantang_sisa_di_jai' => 'boolean',
        'pelanggaran_memberi_makan_kucing' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function catering(): BelongsTo
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function hasDisciplineViolation(): bool
    {
        return (bool) (
            $this->pelanggaran_rantang_tidak_langsung_masuk_mobil ||
            $this->pelanggaran_buka_pilah_rantang_sisa_di_jai ||
            $this->pelanggaran_memberi_makan_kucing
        );
    }

    public function derivedScorePreview(): float
    {
        if ($this->hasDisciplineViolation()) {
            return 4.0;
        }

        $kondisi = strtolower(trim((string) $this->kondisi_kebersihan));
        return match ($kondisi) {
            'bersih' => 10.0,
            'tidak_rapi' => 8.0,
            'kotor' => 6.0,
            default => throw new \InvalidArgumentException("Kondisi kebersihan '{$this->kondisi_kebersihan}' tidak valid."),
        };
    }
}
