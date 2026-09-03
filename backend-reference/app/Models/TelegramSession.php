<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSession extends Model
{
    protected $primaryKey = 'chat_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'chat_id', 'estado', 'ruta_uuid', 'categoria', 'monto', 'nota', 'recibo_path',
        'monto_ocr', 'impuestos_ocr', 'factura_numero_ocr', 'nit_ocr',
    ];
}
