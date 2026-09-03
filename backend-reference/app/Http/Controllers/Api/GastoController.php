<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcesarOcrRecibo;
use App\Models\Gasto;
use Illuminate\Http\Request;

class GastoController extends Controller
{
    // GET /api/gastos?ruta_uuid=... — contable ve todos, conductor ve los suyos
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Gasto::with('ruta');

        if ($user->hasRole('conductor')) {
            $query->where('conductor_id', $user->id);
        }

        if ($request->filled('ruta_uuid')) {
            $query->where('ruta_uuid', $request->ruta_uuid);
        }

        return $query->latest()->get();
    }

    // POST /api/gastos — used by the driver app's sync queue (create)
    // Accepts multipart/form-data when a receipt photo is attached.
    public function store(Request $request)
    {
        $data = $request->validate([
            'uuid' => 'required|uuid',
            'ruta_uuid' => 'required|uuid|exists:rutas,uuid',
            'monto' => 'required|numeric|min:0',
            'impuestos' => 'nullable|numeric|min:0',
            'categoria' => 'required|in:combustible,peaje,comida,hospedaje,mantenimiento,otro',
            'nota' => 'nullable|string',
            'factura_numero' => 'nullable|string|max:255', // vendor's invoice number, off the receipt
            'nit' => 'nullable|string|max:255', // vendor's NIT, off the receipt
            'monto_ocr' => 'nullable|numeric', // on-device Tesseract.js reading
            'creado_offline_en' => 'nullable|date',
            'recibo' => 'nullable|image|max:8192',
            'recibo_2' => 'nullable|image|max:8192', // second photo, e.g. the reverse side
        ]);

        $reciboPath = null;
        if ($request->hasFile('recibo')) {
            // Stored on the 'public' disk so it's servable directly at
            // /storage/{recibo_path} (see `php artisan storage:link`), which
            // is what the web dashboard links to. If you move this to a
            // private disk like 's3', swap that link for a signed URL
            // (Storage::disk('s3')->temporaryUrl(...)) instead.
            $reciboPath = $request->file('recibo')->store('recibos', 'public');
        }

        $reciboPath2 = null;
        if ($request->hasFile('recibo_2')) {
            $reciboPath2 = $request->file('recibo_2')->store('recibos', 'public');
        }

        // Idempotent upsert: the driver app may retry this call if the
        // connection drops mid-sync.
        $gasto = Gasto::updateOrCreate(
            ['uuid' => $data['uuid']],
            [
                'ruta_uuid' => $data['ruta_uuid'],
                'conductor_id' => $request->user()->id,
                'monto' => $data['monto'],
                'impuestos' => $data['impuestos'] ?? null,
                'categoria' => $data['categoria'],
                'nota' => $data['nota'] ?? null,
                'factura_numero' => $data['factura_numero'] ?? null,
                'nit' => $data['nit'] ?? null,
                'monto_ocr' => $data['monto_ocr'] ?? null,
                'creado_offline_en' => $data['creado_offline_en'] ?? null,
                'recibo_path' => $reciboPath ?? null,
                'recibo_path_2' => $reciboPath2 ?? null,
            ]
        );

        // Second-pass cloud OCR runs async so it never blocks the driver's sync.
        if ($reciboPath) {
            ProcesarOcrRecibo::dispatch($gasto);
        }

        return response()->json($gasto, 201);
    }
}
