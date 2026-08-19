<?php

use App\Modules\Cabanas\Http\Controllers\CabanaController;
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
    Route::get('events/{event}/alojamiento', [CabanaController::class, 'alojamiento']);
    Route::delete('eventos-cabanas/{eventoCabana}', [CabanaController::class, 'detach']);
    Route::post('eventos-cabanas-camas/{cama}/asignaciones', [CabanaController::class, 'assign']);
    Route::post('eventos-cabanas-camas/{cama}/autoasignacion', [CabanaController::class, 'selfAssign']);
    Route::post('asignaciones-cama/{asignacion}/liberar', [CabanaController::class, 'release']);
});
