<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Full CRUD for driver accounts, used by the planificador's "Conductores"
// section. Route-level access is restricted to role:planificador (see
// routes/api.php) — conductors themselves never hit these endpoints.
class ConductorController extends Controller
{
    public function index(Request $request)
    {
        return User::role('conductor')->orderBy('name')->get([
            'id', 'name', 'email', 'telefono', 'licencia_conducir', 'created_at',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'telefono' => 'required|string|max:255|unique:users,telefono',
            'licencia_conducir' => 'nullable|string|max:255',
        ]);

        $conductor = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'telefono' => $data['telefono'],
            'licencia_conducir' => $data['licencia_conducir'] ?? null,
        ]);

        $conductor->assignRole('conductor');

        return response()->json($conductor, 201);
    }

    public function update(Request $request, User $conductor)
    {
        if (! $conductor->hasRole('conductor')) {
            abort(404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users', 'email')->ignore($conductor->id)],
            'password' => 'nullable|string|min:8',
            'telefono' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('users', 'telefono')->ignore($conductor->id)],
            'licencia_conducir' => 'nullable|string|max:255',
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $conductor->update($data);

        return response()->json($conductor);
    }

    public function destroy(User $conductor)
    {
        if (! $conductor->hasRole('conductor')) {
            abort(404);
        }

        if (Ruta::where('conductor_id', $conductor->id)->exists()) {
            throw ValidationException::withMessages([
                'conductor' => ['No se puede eliminar: este conductor tiene rutas asignadas.'],
            ]);
        }

        $conductor->delete();

        return response()->noContent();
    }
}
