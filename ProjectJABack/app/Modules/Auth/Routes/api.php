<?php

use App\Modules\Auth\Http\Controllers\AccountMailController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\ParticipantRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('password/forgot', [AccountMailController::class, 'forgot'])->middleware('throttle:5,1');
    Route::post('password/reset', [AccountMailController::class, 'reset'])->middleware('throttle:5,1');
    Route::post('email/verify', [AccountMailController::class, 'verify'])->middleware('throttle:10,1');
    Route::post('email/verify-code', [AccountMailController::class, 'verifyCode'])->middleware('throttle:10,1');
    Route::post('email/resend', [AccountMailController::class, 'resend'])->middleware('throttle:5,1');
    Route::post('email/recover', [AccountMailController::class, 'recover'])->middleware('throttle:5,1');
    Route::post('email/update-pending', [AccountMailController::class, 'updatePendingEmail'])->middleware('throttle:5,1');
    Route::prefix('participant-registration')->group(function () {
        Route::post('start', [ParticipantRegistrationController::class, 'start'])
            ->middleware('throttle:5,1');
        Route::post('verify', [ParticipantRegistrationController::class, 'verify'])
            ->middleware('throttle:10,1');
        Route::post('complete', [ParticipantRegistrationController::class, 'complete'])
            ->middleware('throttle:5,1');
    });
    Route::get('oauth/{provider}/redirect', [AuthController::class, 'redirect'])->middleware('throttle:10,1');
    Route::get('oauth/{provider}/callback', [AuthController::class, 'callback'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('context-options', [AuthController::class, 'contextOptions']);
        Route::post('context', [AuthController::class, 'setContext']);
        Route::delete('context', [AuthController::class, 'clearContext']);
    });
});
