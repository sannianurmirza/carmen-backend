<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequestRecipient extends Model
{
    protected $table = 'approval_request_recipient';
    public $timestamps = false;

    protected $fillable = [
        'approval_request_id',
        'sequence',
        'name',
        'position',
        'email',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
