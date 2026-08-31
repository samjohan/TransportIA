<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Ruta extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid', 'conductor_id', 'planificador_id', 'origen', 'destino',
        'fecha_salida', 'estado',
    ];

    protected $casts = [
        'fecha_salida' => 'datetime',
    ];

    public function conductor()
    {
        return $this->belongsTo(User::class, 'conductor_id');
    }

    public function planificador()
    {
        return $this->belongsTo(User::class, 'planificador_id');
    }

    public function presupuesto()
    {
        return $this->hasOne(Presupuesto::class, 'ruta_uuid', 'uuid');
    }

    public function gastos()
    {
        return $this->hasMany(Gasto::class, 'ruta_uuid', 'uuid');
    }
}
