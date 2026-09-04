<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConductorController;
use App\Http\Controllers\Api\GastoController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\RutaController;
use App\Http\Controllers\Api\UbicacionController;
use Illuminate\Support\Facades\Route;

// throttle:login -> 5 intentos/minuto por email+IP, ver AppServiceProvider::boot()
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Rutas: planificador crea, conductor y contable consultan
    Route::get('/rutas', [RutaController::class, 'index']);
    Route::post('/rutas', [RutaController::class, 'store'])->middleware('role:planificador');
    Route::put('/rutas/{uuid}', [RutaController::class, 'update'])->middleware('role:planificador');
    Route::delete('/rutas/{uuid}', [RutaController::class, 'destroy'])->middleware('role:planificador');

    // Ubicaciones: lista de búsqueda para el selector de origen/destino
    Route::get('/ubicaciones', [UbicacionController::class, 'index']);

    // Conductores: gestión (alta/edición/baja) — solo planificador
    Route::middleware('role:planificador')->group(function () {
        Route::get('/conductores', [ConductorController::class, 'index']);
        Route::post('/conductores', [ConductorController::class, 'store']);
        Route::put('/conductores/{conductor}', [ConductorController::class, 'update']);
        Route::delete('/conductores/{conductor}', [ConductorController::class, 'destroy']);
    });

    // Gastos: conductor crea (con o sin foto), contable/planificador consultan
    Route::get('/gastos', [GastoController::class, 'index']);
    Route::post('/gastos', [GastoController::class, 'store'])->middleware('role:conductor');

    // Reportes: solo contable
    Route::middleware('role:contable')->prefix('reportes')->group(function () {
        Route::get('/presupuesto-vs-gasto', [ReporteController::class, 'presupuestoVsGasto']);
        Route::get('/gastos-por-categoria', [ReporteController::class, 'gastosPorCategoria']);
        Route::get('/gastos-por-conductor', [ReporteController::class, 'gastosPorConductor']);
        Route::get('/discrepancias', [ReporteController::class, 'discrepancias']);
    });
});
