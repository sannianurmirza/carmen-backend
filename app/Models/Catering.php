<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Catering extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'catering';

    public $timestamps = false; // CARMEN handles created_at / updated_at explicitly

    /**
     * Allowed colors in CARMEN
     */
    public const ALLOWED_COLORS = ['biru', 'merah', 'hijau', 'kuning', 'ungu', 'oranye'];

    /**
     * Allowed shapes in CARMEN
     */
    public const ALLOWED_SHAPES = ['circle', 'rectangle'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'nama',
        'normalized_name',
        'warna',
        'color_hex',
        'bentuk',
        'reward_punishment_group',
        'is_active',
        'created_at',
        'updated_at',
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Normalizes a catering name for case-insensitive and whitespace-insensitive deduplication.
     */
    public static function normalizeName(string $name): string
    {
        $cleaned = self::cleanDisplayName($name);
        return mb_strtolower($cleaned, 'UTF-8');
    }

    /**
     * Cleans whitespace from a display name.
     */
    public static function cleanDisplayName(?string $name): string
    {
        if ($name === null) {
            return '';
        }
        $trimmed = trim($name);
        return preg_replace('/\s+/', ' ', $trimmed);
    }

    /**
     * Resolves and normalizes color name.
     */
    public static function resolveColor(?string $warna): string
    {
        $w = strtolower(trim((string)$warna));
        if (in_array($w, self::ALLOWED_COLORS, true)) {
            return $w;
        }
        return 'biru';
    }

    /**
     * Serializes model into API contract array matching FastAPI CateringResponse.
     */
    public function toResponseArray(): array
    {
        return [
            'id' => (int)$this->id,
            'nama' => (string)$this->nama,
            'warna' => $this->warna ?: 'biru',
            'color_hex' => $this->color_hex,
            'bentuk' => $this->bentuk ?: 'circle',
            'reward_punishment_group' => $this->reward_punishment_group,
            'is_active' => $this->is_active !== null ? (bool)$this->is_active : true,
            'created_at' => $this->created_at ? $this->created_at->toISOString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toISOString() : null,
        ];
    }
}
