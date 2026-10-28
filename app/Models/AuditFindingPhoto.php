<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditFindingPhoto extends Model
{
    protected $table = 'auditfindingphoto';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'finding_id',
        'relative_path',
        'original_filename',
        'stored_filename',
        'mime_type',
        'file_size',
        'sha256',
        'created_at',
    ];

    protected $casts = [
        'finding_id' => 'integer',
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function finding()
    {
        return $this->belongsTo(AuditFinding::class, 'finding_id');
    }
}
