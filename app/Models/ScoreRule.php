<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScoreRule extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'scorerule';

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'kategori',
        'label',
        'batas_bawah',
        'batas_atas',
        'grade',
        'score',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batas_bawah' => 'float',
            'batas_atas' => 'float',
            'score' => 'float',
        ];
    }

    /**
     * Serialization for GET /score-rules matching FastAPI ScoreRule model.
     */
    public function toResponseArray(): array
    {
        return [
            'id' => (int)$this->id,
            'kategori' => (string)$this->kategori,
            'label' => (string)$this->label,
            'batas_bawah' => $this->batas_bawah !== null ? (float)$this->batas_bawah : null,
            'batas_atas' => $this->batas_atas !== null ? (float)$this->batas_atas : null,
            'grade' => $this->grade !== null ? (string)$this->grade : null,
            'score' => (float)$this->score,
        ];
    }
}
