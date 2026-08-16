<?php

use App\Modules\Organizations\Http\Controllers\OrganizacionController;
use App\Modules\Organizations\Http\Controllers\UbicacionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('organizaciones/tipos', [OrganizacionController::class, 'tipos']);
    Route::get('organizaciones/parent-options', [OrganizacionController::class, 'parentOptions']);
    Route::get('organizaciones/tree', [OrganizacionController::class, 'tree']);
    Route::get('ubicacion/paises', [UbicacionController::class, 'paises']);
    Route::get('ubicacion/departamentos', [UbicacionController::class, 'departamentos']);
    Route::get('ubicacion/ciudades', [UbicacionController::class, 'ciudades']);
    Route::get('organizaciones', [OrganizacionController::class, 'index']);
    Route::post('organizaciones', [OrganizacionController::class, 'store']);
    Route::get('organizaciones/{organizacion}', [OrganizacionController::class, 'show']);
    Route::put('organizaciones/{organizacion}', [OrganizacionController::class, 'update']);
    Route::patch('organizaciones/{organizacion}', [OrganizacionController::class, 'update']);
    Route::delete('organizaciones/{organizacion}', [OrganizacionController::class, 'destroy']);
});
