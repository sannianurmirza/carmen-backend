<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BahanStandar extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'bahanstandar';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nama',
        'standar_kalori',
        'standar_berat',
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
            'standar_kalori' => 'float',
            'standar_berat' => 'float',
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
            'updated_by' => 'integer',
        ];
    }

    /**
     * User who last updated this standard.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Serialization for GET /bahan-standar matching FastAPI BahanStandar model.
     */
    public function toResponseArray(): array
    {
        return [
            'id' => (int)$this->id,
            'nama' => (string)$this->nama,
            'standar_kalori' => (float)$this->standar_kalori,
            'standar_berat' => $this->standar_berat !== null ? (float)$this->standar_berat : null,
            'satuan' => $this->satuan ?: 'gram',
            'is_active' => $this->is_active !== null ? (bool)$this->is_active : true,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_by' => $this->updated_by ? (int)$this->updated_by : null,
        ];
    }

    /**
     * Serialization for GET /settings/calorie-standard matching FastAPI CalorieStandardItemOut.
     */
    public function toCalorieStandardArray(?string $updaterName = null): array
    {
        return [
            'id' => (int)$this->id,
            'nama' => (string)$this->nama,
            'standar_kalori' => (float)$this->standar_kalori,
            'is_active' => $this->is_active !== null ? (bool)$this->is_active : true,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_by' => $this->updated_by ? (int)$this->updated_by : null,
            'updater_name' => $updaterName ?: ($this->updater ? $this->updater->nama : null),
        ];
    }

    /**
     * Serialization for GET /settings/calorie-master matching FastAPI CalorieMasterItemOut.
     */
    public function toCalorieMasterArray(?string $updaterName = null): array
    {
        return [
            'id' => (int)$this->id,
            'nama' => (string)$this->nama,
            'standar_kalori' => (float)$this->standar_kalori,
            'standar_berat' => $this->standar_berat !== null ? (float)$this->standar_berat : null,
            'satuan' => $this->satuan ?: 'gram',
            'is_active' => $this->is_active !== null ? (bool)$this->is_active : true,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
            'updated_by' => $this->updated_by ? (int)$this->updated_by : null,
            'updater_name' => $updaterName ?: ($this->updater ? $this->updater->nama : null),
        ];
    }
}
