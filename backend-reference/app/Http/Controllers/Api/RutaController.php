<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Presupuesto;
use App\Models\Ruta;
use App\Models\Ubicacion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RutaController extends Controller
{
    // GET /api/rutas
    // Planificador/contable: ve todas. Conductor: solo las suyas.
    public function index(Request $request)
    {
        $user = $request->user();

        // total_gastado backs the anticipo-vs-gastado comparison in the
        // planificador's grid — sum of this route's gastos, not a stored column.
        $query = Ruta::with(['presupuesto', 'conductor'])->withSum('gastos as total_gastado', 'monto');

        if ($user->hasRole('conductor')) {
            $query->where('conductor_id', $user->id);
        }

        return $query->latest()->get();
    }

    // POST /api/rutas — solo planificador (ver middleware('role:planificador') en routes/api.php)
    public function store(Request $request)
    {
        $data = $request->validate([
            'conductor_id' => 'required|exists:users,id',
            'origen' => 'required|string|max:255',
            'destino' => 'required|string|max:255',
            'fecha_salida' => 'nullable|date',
            'monto_asignado' => 'required|numeric|min:0',
        ]);

        $conductor = User::findOrFail($data['conductor_id']);
        if (! $conductor->hasRole('conductor')) {
            throw ValidationException::withMessages([
                'conductor_id' => ['El usuario seleccionado no tiene el rol de conductor.'],
            ]);
        }

        $ruta = Ruta::create([
            'conductor_id' => $data['conductor_id'],
            'planificador_id' => $request->user()->id,
            'origen' => $data['origen'],
            'destino' => $data['destino'],
            'fecha_salida' => $data['fecha_salida'] ?? null,
            'estado' => 'pendiente',
        ]);

        // The origen/destino picker in the web dashboard is a searchable
        // list backed by this table — it isn't managed by hand, it just
        // grows with whatever real places show up here.
        Ubicacion::firstOrCreate(['nombre' => $data['origen']]);
        Ubicacion::firstOrCreate(['nombre' => $data['destino']]);

        // App exclusiva para operación en Colombia: la moneda siempre es COP,
        // no es un dato que el planificador deba escoger.
        Presupuesto::create([
            'ruta_uuid' => $ruta->uuid,
            'monto_asignado' => $data['monto_asignado'],
            'moneda' => 'COP',
        ]);

        return response()->json($ruta->load('presupuesto'), 201);
    }

    // PUT /api/rutas/{uuid} — solo planificador. Todos los campos son
    // opcionales (edición parcial): solo se actualiza lo que venga en el payload.
    public function update(Request $request, string $uuid)
    {
        $ruta = Ruta::with('presupuesto')->findOrFail($uuid);

        $data = $request->validate([
            'conductor_id' => 'sometimes|required|exists:users,id',
            'origen' => 'sometimes|required|string|max:255',
            'destino' => 'sometimes|required|string|max:255',
            'fecha_salida' => 'nullable|date',
            'monto_asignado' => 'sometimes|required|numeric|min:0',
            'estado' => 'sometimes|in:pendiente,en_curso,completada,cancelada',
        ]);

        if (array_key_exists('conductor_id', $data)) {
            $conductor = User::findOrFail($data['conductor_id']);
            if (! $conductor->hasRole('conductor')) {
                throw ValidationException::withMessages([
                    'conductor_id' => ['El usuario seleccionado no tiene el rol de conductor.'],
                ]);
            }
        }

        $ruta->update(collect($data)->only(['conductor_id', 'origen', 'destino', 'fecha_salida', 'estado'])->toArray());

        foreach (['origen', 'destino'] as $campo) {
            if (isset($data[$campo])) {
                Ubicacion::firstOrCreate(['nombre' => $data[$campo]]);
            }
        }

        if (isset($data['monto_asignado']) && $ruta->presupuesto) {
            $ruta->presupuesto->update(['monto_asignado' => $data['monto_asignado']]);
        }

        return response()->json($ruta->fresh(['presupuesto', 'conductor']));
    }
}
