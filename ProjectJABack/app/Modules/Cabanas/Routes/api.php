<?php

use App\Modules\Cabanas\Http\Controllers\CabanaController;
use App\Modules\Cabanas\Http\Controllers\EventoAlojamientoCupoController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('cabanas', [CabanaController::class, 'index']);
    Route::post('cabanas', [CabanaController::class, 'store']);
    Route::get('cabanas/{cabana}', [CabanaController::class, 'show']);
    Route::put('cabanas/{cabana}', [CabanaController::class, 'update']);
    Route::patch('cabanas/{cabana}', [CabanaController::class, 'update']);
    Route::delete('cabanas/{cabana}', [CabanaController::class, 'destroy']);
    Route::put('cabanas/{cabana}/croquis', [CabanaController::class, 'saveCroquis']);
    Route::post('cabanas/{cabana}/image', [CabanaController::class, 'image']);

    Route::get('events/{event}/cabanas', [CabanaController::class, 'eventIndex']);
    Route::post('events/{event}/cabanas', [CabanaController::class, 'attach']);
    Route::put('events/{event}/cabanas', [CabanaController::class, 'sync']);
    Route::put('events/{event}/cabanas/precios', [CabanaController::class, 'updateBedPrices']);
    Route::get('events/{event}/alojamiento', [CabanaController::class, 'alojamiento']);
    Route::get('events/{event}/alojamiento/cupos', [EventoAlojamientoCupoController::class, 'index']);
    Route::put('events/{event}/alojamiento/cupos', [EventoAlojamientoCupoController::class, 'sync']);
    Route::get('events/{event}/alojamiento/cupos/candidatos', [EventoAlojamientoCupoController::class, 'candidates']);
    Route::post('events/{event}/alojamiento/cupos/{cupo}/asignaciones', [EventoAlojamientoCupoController::class, 'assign']);
    Route::post('events/{event}/alojamiento/cupos/{cupo}/cerrar', [EventoAlojamientoCupoController::class, 'close']);
    Route::delete('eventos-cabanas/{eventoCabana}', [CabanaController::class, 'detach']);
    Route::post('eventos-cabanas-camas/{cama}/asignaciones', [CabanaController::class, 'assign']);
    Route::post('eventos-cabanas-camas/{cama}/autoasignacion', [CabanaController::class, 'selfAssign']);
    Route::post('asignaciones-cama/{asignacion}/liberar', [CabanaController::class, 'release']);
});
