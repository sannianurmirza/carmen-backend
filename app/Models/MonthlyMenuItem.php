<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyMenuItem extends Model
{
    protected $table = 'monthly_menu_item';

    public $timestamps = false;

    protected $fillable = [
        'plan_id',
        'menu_date',
        'shift',
        'item_order',
        'item_role',
        'menu_name',
        'notes',
    ];

    protected $casts = [
        'menu_date' => 'date:Y-m-d',
        'item_order' => 'integer',
    ];

    public function setMenuDateAttribute($value): void
    {
        if ($value instanceof \DateTimeInterface) {
            $this->attributes['menu_date'] = $value->format('Y-m-d');
        } elseif (is_string($value)) {
            $this->attributes['menu_date'] = substr($value, 0, 10);
        } else {
            $this->attributes['menu_date'] = $value;
        }
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MonthlyMenuPlan::class, 'plan_id');
    }
}
