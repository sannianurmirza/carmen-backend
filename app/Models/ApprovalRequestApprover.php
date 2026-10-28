<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequestApprover extends Model
{
    protected $table = 'approval_request_approver';

    public $timestamps = true;

    protected $fillable = [
        'approval_request_id',
        'cycle',
        'sequence',
        'name',
        'position',
        'email',
        'matched_user_id',
        'status',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
        'revision_comment',
    ];

    protected $casts = [
        'cycle' => 'integer',
        'sequence' => 'integer',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function matchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
