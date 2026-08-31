<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ubicacion;
use Illuminate\Http\Request;

// Read-only lookup for the searchable origen/destino selector. The list
// itself grows automatically — see RutaController::store(), which
// registers any new origen/destino here — so there's no manage-by-hand
// create/update/delete for this resource.
class UbicacionController extends Controller
{
    public function index(Request $request)
    {
        $query = Ubicacion::query();

        if ($request->filled('q')) {
            $query->where('nombre', 'ilike', '%'.$request->q.'%');
        }

        return $query->orderBy('nombre')->limit(50)->get(['id', 'nombre']);
    }
}
