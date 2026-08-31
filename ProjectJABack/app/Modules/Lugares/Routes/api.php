<?php

use App\Modules\Lugares\Http\Controllers\LugarController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('lugares', [LugarController::class, 'index']);
    Route::get('lugares/catalogos', [LugarController::class, 'catalogos']);
    Route::post('lugares', [LugarController::class, 'store']);
    Route::get('lugares/{lugar}', [LugarController::class, 'show']);
    Route::put('lugares/{lugar}', [LugarController::class, 'update']);
    Route::patch('lugares/{lugar}', [LugarController::class, 'update']);
    Route::delete('lugares/{lugar}', [LugarController::class, 'destroy']);
});
