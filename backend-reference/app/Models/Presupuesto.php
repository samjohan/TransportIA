<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Presupuesto extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['uuid', 'ruta_uuid', 'monto_asignado', 'moneda'];

    public function ruta()
    {
        return $this->belongsTo(Ruta::class, 'ruta_uuid', 'uuid');
    }
}
