<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ApprovalRequest extends Model
{
    protected $table = 'approval_request';

    public $timestamps = true;

    protected $fillable = [
        'delivery_type',
        'requester_user_id',
        'requester_name',
        'original_filename',
        'stored_file_path',
        'file_size_bytes',
        'mime_type',
        'note',
        'recipient_emails',
        'status',
        'requested_at',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'requested_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ApprovalRequestRevision::class, 'approval_request_id');
    }

    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalRequestApprover::class, 'approval_request_id')->orderBy('sequence');
    }

    public function menuPlan(): HasOne
    {
        return $this->hasOne(MonthlyMenuPlan::class, 'approval_request_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ApprovalArtifact::class, 'approval_request_id')->orderBy('created_at');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ApprovalRequestRecipient::class, 'approval_request_id')->orderBy('sequence');
    }
}
