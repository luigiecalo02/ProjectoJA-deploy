<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Http\Controllers\NotificationSettingsController;
use App\Modules\Notifications\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('push/vapid-key', [PushSubscriptionController::class, 'vapidKey']);
    Route::post('push/subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('push/subscriptions', [PushSubscriptionController::class, 'destroy']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('settings/clubes/notificaciones', [NotificationSettingsController::class, 'index']);
    Route::put('settings/clubes/notificaciones/{tipo}', [NotificationSettingsController::class, 'update']);
});
