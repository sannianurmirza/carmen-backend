<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StandarBerat extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'standarberat';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'jenis_masakan',
        'item_role',
        'berat_gram',
        'satuan',
        'is_active',
        'updated_at',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'berat_gram' => 'float',
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
            'updated_by' => 'integer',
        ];
    }

    /**
     * User who last updated this weight standard.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Serialization for GET /standar-berat matching FastAPI StandarBerat model.
     */
    public function toResponseArray(): array
    {
        return [
            'id' => (int)$this->id,
            'jenis_masakan' => (string)$this->jenis_masakan,
            'item_role' => (string)$this->item_role,
            'berat_gram' => (float)$this->berat_gram,
            'satuan' => $this->satuan ?: 'gram',
            'is_active' => $this->is_active !== null ? (bool)$this->is_active : true,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_by' => $this->updated_by ? (int)$this->updated_by : null,
        ];
    }

    /**
     * Serialization for GET /settings/standar-berat matching FastAPI StandarBeratOut.
     */
    public function toSettingsArray(?string $updaterName = null): array
    {
        return [
            'id' => (int)$this->id,
            'jenis_masakan' => (string)$this->jenis_masakan,
            'item_role' => (string)$this->item_role,
            'berat_gram' => (float)$this->berat_gram,
            'satuan' => $this->satuan ?: 'gram',
            'is_active' => $this->is_active !== null ? (bool)$this->is_active : true,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_by' => $this->updated_by ? (int)$this->updated_by : null,
            'updater_name' => $updaterName ?: ($this->updater ? $this->updater->nama : null),
        ];
    }
}
