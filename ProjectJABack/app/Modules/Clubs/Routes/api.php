<?php

use App\Modules\Clubs\Http\Controllers\ClubController;
use App\Modules\Clubs\Http\Controllers\ClubInscripcionController;
use App\Modules\Clubs\Http\Controllers\PersonaController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/club-inscripcion')->group(function () {
    Route::get('catalogo', [ClubInscripcionController::class, 'catalog'])->middleware('throttle:30,1');
    Route::get('clubes', [ClubInscripcionController::class, 'clubes'])->middleware('throttle:30,1');
    Route::get('opciones', [ClubInscripcionController::class, 'options'])->middleware('throttle:30,1');
    Route::get('paises', [ClubInscripcionController::class, 'paises'])->middleware('throttle:30,1');
    Route::get('departamentos', [ClubInscripcionController::class, 'departamentos'])->middleware('throttle:30,1');
    Route::get('ciudades', [ClubInscripcionController::class, 'ciudades'])->middleware('throttle:30,1');
    Route::post('/', [ClubInscripcionController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('clubs/directors-catalog', [ClubController::class, 'directorsCatalog']);
    Route::get('clubs/available-for-account', [ClubController::class, 'availableForAccount']);
    Route::get('clubs/iglesia-options', [ClubController::class, 'iglesiaOptions']);
    Route::get('clubs/current', [ClubController::class, 'current']);
    Route::get('clubs', [ClubController::class, 'index']);
    Route::post('clubs', [ClubController::class, 'store']);
    Route::get('clubs/{club}', [ClubController::class, 'show']);
    Route::put('clubs/{club}', [ClubController::class, 'update']);
    Route::patch('clubs/{club}', [ClubController::class, 'update']);
    Route::delete('clubs/{club}', [ClubController::class, 'destroy']);
    Route::post('clubs/{club}/logo', [ClubController::class, 'logo']);
    Route::put('clubs/{club}/members', [ClubController::class, 'members']);
    Route::put('clubs/{club}/directors', [ClubController::class, 'directors']);

    Route::get('personas', [PersonaController::class, 'index']);
    Route::get('personas/organizacion-options', [PersonaController::class, 'organizacionOptions']);
    Route::post('personas', [PersonaController::class, 'store']);
    Route::get('personas/{persona}', [PersonaController::class, 'show']);
    Route::put('personas/{persona}', [PersonaController::class, 'update']);
    Route::patch('personas/{persona}', [PersonaController::class, 'update']);
    Route::delete('personas/{persona}', [PersonaController::class, 'destroy']);
});
