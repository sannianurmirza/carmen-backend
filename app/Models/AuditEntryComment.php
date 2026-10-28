<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEntryComment extends Model
{
    protected $table = 'auditentrycomment';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'audit_entry_id',
        'sisi',
        'kategori',
        'comment',
    ];

    protected $casts = [
        'audit_entry_id' => 'integer',
    ];

    public function auditEntry()
    {
        return $this->belongsTo(AuditEntry::class, 'audit_entry_id');
    }
}
