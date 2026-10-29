<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SMTPConfig extends Model
{
    protected $table = 'smtp_config';

    public $timestamps = false; // CARMEN handles updated_at explicitly

    protected $fillable = [
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password_encrypted',
        'email_sender',
        'sender_name',
        'use_tls',
        'use_ssl',
        'app_url',
        'is_active',
        'updated_at',
        'updated_by',
    ];

    protected $casts = [
        'smtp_port' => 'integer',
        'use_tls' => 'boolean',
        'use_ssl' => 'boolean',
        'is_active' => 'boolean',
        'updated_at' => 'datetime',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
