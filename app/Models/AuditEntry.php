<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEntry extends Model
{
    protected $table = 'auditentry';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'tanggal',
        'assessment_mode',
        'status',
        'is_tanpa_temuan',
        'catatan_umum',
        'created_by',
        'finalized_by',
        'created_by_name_snapshot',
        'finalized_by_name_snapshot',
        'created_at',
        'updated_at',
        'finalized_at',
    ];

    protected $casts = [
        'catering_id' => 'integer',
        'tanggal' => 'date',
        'is_tanpa_temuan' => 'boolean',
        'created_by' => 'integer',
        'finalized_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function catering()
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizedBy()
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function items()
    {
        return $this->hasMany(AuditEntryItem::class, 'audit_entry_id');
    }

    public function comments()
    {
        return $this->hasMany(AuditEntryComment::class, 'audit_entry_id');
    }

    public function findings()
    {
        return $this->hasMany(AuditFinding::class, 'audit_entry_id');
    }

    public function getNilaiAuditAttribute(): float
    {
        if (isset($this->attributes['nilai_audit'])) {
            return (float)$this->attributes['nilai_audit'];
        }
        try {
            $auditService = app(\App\Services\AuditService::class);
            $res = $auditService->computeScore($this);
            return (float)($res['nilai_audit'] ?? 0.0);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    public function setNilaiAuditAttribute($value): void
    {
        $this->attributes['nilai_audit'] = (float)$value;
    }
}

