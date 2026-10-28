<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditFinding extends Model
{
    protected $table = 'auditfinding';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'audit_entry_id',
        'audit_kriteria_id',
        'keterangan',
        'nama_kriteria_snapshot',
        'kategori_snapshot',
        'material_snapshot',
        'sisi_snapshot',
        'point_snapshot',
        'created_by',
        'created_by_name_snapshot',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'audit_entry_id' => 'integer',
        'audit_kriteria_id' => 'integer',
        'point_snapshot' => 'float',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function auditEntry()
    {
        return $this->belongsTo(AuditEntry::class, 'audit_entry_id');
    }

    public function auditKriteria()
    {
        return $this->belongsTo(AuditKriteria::class, 'audit_kriteria_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function photos()
    {
        return $this->hasMany(AuditFindingPhoto::class, 'finding_id');
    }
}
