<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalArtifact extends Model
{
    protected $table = 'approval_artifact';
    public $timestamps = false;

    protected $fillable = [
        'approval_request_id',
        'approver_user_id',
        'approver_name_snapshot',
        'approver_position_snapshot',
        'sequence',
        'cycle',
        'revision_number',
        'stage',
        'source_file_path',
        'source_file_sha256',
        'output_file_path',
        'output_file_sha256',
        'status',
        'generation_error',
        'approved_at',
        'created_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'cycle' => 'integer',
        'revision_number' => 'integer',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
