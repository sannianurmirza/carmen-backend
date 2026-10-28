<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KomplainCatering extends Model
{
    protected $table = 'komplain_catering';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'tanggal',
        'shift',
        'source_report_id',
        'menu_snapshot',
        'foto_path',
        'keterangan',
        'created_by',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'catering_id' => 'integer',
        'tanggal' => 'date',
        'source_report_id' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function catering()
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceReport()
    {
        return $this->belongsTo(KaloriHarian::class, 'source_report_id');
    }
}
