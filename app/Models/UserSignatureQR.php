<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSignatureQR extends Model
{
    protected $table = 'user_signature_qr';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'signature_token',
        'qr_image_path',
        'is_active',
        'created_at',
        'updated_at',
        'regenerated_at',
        'revoked_at',
        'note',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'regenerated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
