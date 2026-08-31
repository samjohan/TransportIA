<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Gasto extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'uuid', 'ruta_uuid', 'conductor_id', 'monto', 'categoria', 'nota',
        'monto_ocr', 'monto_ocr_servidor', 'ocr_discrepancia',
        'recibo_path', 'creado_offline_en',
    ];

    protected $casts = [
        'creado_offline_en' => 'datetime',
        'ocr_discrepancia' => 'boolean',
    ];

    public function ruta()
    {
        return $this->belongsTo(Ruta::class, 'ruta_uuid', 'uuid');
    }

    public function conductor()
    {
        return $this->belongsTo(User::class, 'conductor_id');
    }

    // Called after the server-side (cloud) OCR pass completes.
    // Flags the expense for accountant review if the server's reading
    // disagrees meaningfully with what the driver confirmed.
    public function marcarDiscrepanciaSiAplica(float $tolerancia = 0.05): void
    {
        if (is_null($this->monto_ocr_servidor)) {
            return;
        }

        $diferencia = abs($this->monto - $this->monto_ocr_servidor) / max($this->monto, 0.01);
        $this->ocr_discrepancia = $diferencia > $tolerancia;
        $this->save();
    }
}
