<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonthlyMenuPlan extends Model
{
    protected $table = 'monthly_menu_plan';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'submission_period',
        'effective_menu_period',
        'effective_year',
        'effective_month',
        'version',
        'status',
        'is_active',
        'approval_request_id',
        'catatan',
        'created_by_user_id',
        'approved_at',
        'activated_at',
    ];

    protected $casts = [
        'effective_year' => 'integer',
        'effective_month' => 'integer',
        'version' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function catering(): BelongsTo
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MonthlyMenuItem::class, 'plan_id')->orderBy('menu_date')->orderBy('shift')->orderBy('item_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
