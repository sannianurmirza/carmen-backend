<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditKriteria extends Model
{
    protected $table = 'auditkriteria';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'sisi',
        'kategori',
        'material',
        'deskripsi',
        'point',
    ];

    protected $casts = [
        'point' => 'float',
    ];
}
