<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Keterlambatan extends Model
{
    protected $table = 'keterlambatan';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'tanggal',
        'shift',
        'is_terlambat',
        'status_pengiriman',
        'menit_terlambat',
        'kategori',
        'komentar',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'catering_id' => 'integer',
        'tanggal' => 'date',
        'is_terlambat' => 'boolean',
        'menit_terlambat' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function catering()
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }
}
