<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ubicacion extends Model
{
    use HasFactory;

    // Eloquent's pluralizer doesn't know Spanish rules — left to guess, it
    // looks for "ubicacions".
    protected $table = 'ubicaciones';

    protected $fillable = ['nombre'];
}
