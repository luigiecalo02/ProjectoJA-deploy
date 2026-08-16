<?php

use App\Modules\Terrains\Http\Controllers\DistribucionEventoController;
use App\Modules\Terrains\Http\Controllers\TerrenoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('terrenos', [TerrenoController::class, 'index']);
    Route::post('terrenos', [TerrenoController::class, 'store']);
    Route::get('terrenos/{terreno}', [TerrenoController::class, 'show']);
    Route::put('terrenos/{terreno}', [TerrenoController::class, 'update']);
    Route::patch('terrenos/{terreno}', [TerrenoController::class, 'update']);
    Route::delete('terrenos/{terreno}', [TerrenoController::class, 'destroy']);
    Route::post('terrenos/{terreno}/imagen', [TerrenoController::class, 'imagen']);

    Route::get('terrenos/{terreno}/estructuras', [TerrenoController::class, 'estructurasIndex']);
    Route::post('terrenos/{terreno}/estructuras', [TerrenoController::class, 'estructurasStore']);
    Route::put('estructuras-terreno/{estructura}', [TerrenoController::class, 'estructurasUpdate']);
    Route::patch('estructuras-terreno/{estructura}', [TerrenoController::class, 'estructurasUpdate']);
    Route::delete('estructuras-terreno/{estructura}', [TerrenoController::class, 'estructurasDestroy']);

    Route::get('terrenos/{terreno}/configuraciones', [TerrenoController::class, 'configsIndex']);
    Route::post('terrenos/{terreno}/configuraciones', [TerrenoController::class, 'configsStore']);
    Route::get('configuraciones-terreno/{configuracion}', [TerrenoController::class, 'configsShow']);
    Route::put('configuraciones-terreno/{configuracion}', [TerrenoController::class, 'configsUpdate']);
    Route::patch('configuraciones-terreno/{configuracion}', [TerrenoController::class, 'configsUpdate']);
    Route::delete('configuraciones-terreno/{configuracion}', [TerrenoController::class, 'configsDestroy']);
    Route::post('configuraciones-terreno/{configuracion}/duplicar', [TerrenoController::class, 'configsDuplicate']);

    Route::post('configuraciones-terreno/{configuracion}/zonas', [TerrenoController::class, 'zonasStore']);
    Route::put('zonas-terreno/{zona}', [TerrenoController::class, 'zonasUpdate']);
    Route::patch('zonas-terreno/{zona}', [TerrenoController::class, 'zonasUpdate']);
    Route::delete('zonas-terreno/{zona}', [TerrenoController::class, 'zonasDestroy']);

    Route::post('zonas-terreno/{zona}/lotes', [TerrenoController::class, 'lotesStoreOnZona']);
    Route::post('configuraciones-terreno/{configuracion}/lotes', [TerrenoController::class, 'lotesStoreOnConfig']);
    Route::put('lotes-terreno/{lote}', [TerrenoController::class, 'lotesUpdate']);
    Route::patch('lotes-terreno/{lote}', [TerrenoController::class, 'lotesUpdate']);
    Route::delete('lotes-terreno/{lote}', [TerrenoController::class, 'lotesDestroy']);

    Route::get('events/{event}/distribucion', [DistribucionEventoController::class, 'show']);
    Route::post('events/{event}/distribucion', [DistribucionEventoController::class, 'attach']);
    Route::delete('events/{event}/distribucion', [DistribucionEventoController::class, 'detach']);

    Route::post('eventos-terrenos/{eventoTerreno}/zonas', [DistribucionEventoController::class, 'storeZona']);
    Route::put('eventos-zonas/{eventoZona}', [DistribucionEventoController::class, 'updateZona']);
    Route::delete('eventos-zonas/{eventoZona}', [DistribucionEventoController::class, 'destroyZona']);

    Route::post('eventos-terrenos/{eventoTerreno}/lotes', [DistribucionEventoController::class, 'storeLoteOnTerreno']);
    Route::post('eventos-zonas/{eventoZona}/lotes', [DistribucionEventoController::class, 'storeLote']);
    Route::put('eventos-lotes/{eventoLote}', [DistribucionEventoController::class, 'updateLote']);
    Route::delete('eventos-lotes/{eventoLote}', [DistribucionEventoController::class, 'destroyLote']);

    Route::post('eventos-lotes/{eventoLote}/asignaciones', [DistribucionEventoController::class, 'assign']);
    Route::post('eventos-lotes/{eventoLote}/autoasignacion', [DistribucionEventoController::class, 'selfAssign']);
    Route::put('asignaciones-lotes/{asignacion}', [DistribucionEventoController::class, 'updateAsignacion']);
    Route::post('asignaciones-lotes/{asignacion}/liberar', [DistribucionEventoController::class, 'liberar']);
});
