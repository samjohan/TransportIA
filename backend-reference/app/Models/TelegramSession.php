<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSession extends Model
{
    protected $primaryKey = 'chat_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['chat_id', 'estado', 'ruta_uuid', 'categoria', 'monto', 'nota'];
}
