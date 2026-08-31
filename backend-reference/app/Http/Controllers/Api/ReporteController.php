<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gasto;
use App\Models\Ruta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    // GET /api/reportes/presupuesto-vs-gasto — for the accountant's bar chart
    public function presupuestoVsGasto(Request $request)
    {
        return Ruta::with('presupuesto')
            ->withSum('gastos as total_gastado', 'monto')
            ->get()
            ->map(fn ($ruta) => [
                'ruta' => "{$ruta->origen} → {$ruta->destino}",
                'presupuesto' => $ruta->presupuesto?->monto_asignado ?? 0,
                'gastado' => $ruta->total_gastado ?? 0,
            ]);
    }

    // GET /api/reportes/gastos-por-categoria — for a pie/bar chart
    public function gastosPorCategoria(Request $request)
    {
        return Gasto::select('categoria', DB::raw('SUM(monto) as total'))
            ->groupBy('categoria')
            ->get();
    }

    // GET /api/reportes/gastos-por-conductor
    public function gastosPorConductor(Request $request)
    {
        return Gasto::join('users', 'users.id', '=', 'gastos.conductor_id')
            ->select('users.name as conductor', DB::raw('SUM(gastos.monto) as total'))
            ->groupBy('users.name')
            ->get();
    }

    // GET /api/reportes/discrepancias — expenses flagged by server OCR review
    public function discrepancias(Request $request)
    {
        return Gasto::where('ocr_discrepancia', true)->with(['ruta', 'conductor'])->get();
    }
}
