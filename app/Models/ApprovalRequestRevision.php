<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequestRevision extends Model
{
    protected $table = 'approval_request_revision';

    public $timestamps = false;

    protected $fillable = [
        'approval_request_id',
        'revision_number',
        'original_filename',
        'stored_file_path',
        'file_size_bytes',
        'mime_type',
        'submitted_by',
        'submitted_at',
        'note',
        'created_at',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'file_size_bytes' => 'integer',
        'submitted_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
