<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KelayakanEntry extends Model
{
    protected $table = 'kelayakanentry';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = true;

    protected $fillable = [
        'catering_id',
        'tanggal',
        'shift',
        'nasi_kelayakan',
        'nasi_rasa',
        'lauk_kelayakan',
        'lauk_rasa',
        'sayur_kelayakan',
        'sayur_rasa',
        'sambal_kelayakan',
        'sambal_rasa',
        'kerupuk_kelayakan',
        'kerupuk_rasa',
        'buah_kelayakan',
        'buah_rasa',
        'kondisi_rantang',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'catering_id' => 'integer',
        'tanggal' => 'date',
        'nasi_kelayakan' => 'integer',
        'nasi_rasa' => 'integer',
        'lauk_kelayakan' => 'integer',
        'lauk_rasa' => 'integer',
        'sayur_kelayakan' => 'integer',
        'sayur_rasa' => 'integer',
        'sambal_kelayakan' => 'integer',
        'sambal_rasa' => 'integer',
        'kerupuk_kelayakan' => 'integer',
        'kerupuk_rasa' => 'integer',
        'buah_kelayakan' => 'integer',
        'buah_rasa' => 'integer',
        'kondisi_rantang' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function catering()
    {
        return $this->belongsTo(Catering::class, 'catering_id');
    }
}
