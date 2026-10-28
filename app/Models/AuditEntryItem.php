<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEntryItem extends Model
{
    protected $table = 'auditentryitem';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'audit_entry_id',
        'audit_kriteria_id',
        'tercentang',
        'nama_kriteria_snapshot',
        'kategori_snapshot',
        'material_snapshot',
        'sisi_snapshot',
        'point_snapshot',
    ];

    protected $casts = [
        'audit_entry_id' => 'integer',
        'audit_kriteria_id' => 'integer',
        'tercentang' => 'boolean',
        'point_snapshot' => 'float',
    ];

    public function auditKriteria()
    {
        return $this->belongsTo(AuditKriteria::class, 'audit_kriteria_id');
    }

    public function auditEntry()
    {
        return $this->belongsTo(AuditEntry::class, 'audit_entry_id');
    }
}
