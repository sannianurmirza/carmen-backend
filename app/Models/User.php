<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'password_hash',
        'nama',
        'email',
        'role',
        'is_active',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the password for the user (FastAPI compatibility).
     *
     * @return string
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Sessions relationship.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class, 'user_id');
    }

    /**
     * User signature QR relationship.
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(UserSignatureQR::class, 'user_id');
    }

    public function activeSignature()
    {
        return $this->hasOne(UserSignatureQR::class, 'user_id')->where('is_active', true)->latestOfMany();
    }

    /**
     * Role helpers
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'staff']);
    }

    public function isApprover(): bool
    {
        return in_array($this->role, ['admin', 'approver']);
    }

    public function isVendor(): bool
    {
        return $this->role === 'user';
    }

    public function getCateringIdAttribute(): ?int
    {
        $ident = strtolower($this->username . ' ' . ($this->email ?? ''));
        if (str_contains($ident, 'alpha')) {
            return 1;
        }
        if (str_contains($ident, 'beta')) {
            return 2;
        }
        return null;
    }
}
