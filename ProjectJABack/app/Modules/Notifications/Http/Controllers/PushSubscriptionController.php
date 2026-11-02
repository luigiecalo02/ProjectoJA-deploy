<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PushSubscriptionController
{
    public function vapidKey(): JsonResponse
    {
        $publicKey = (string) config('webpush.vapid.public_key', '');
        abort_unless($publicKey !== '', 503, 'Las notificaciones push no están configuradas.');

        return ApiResponse::success(['public_key' => $publicKey]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string', 'max:32'],
            'content_encoding' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $encoding = $data['contentEncoding'] ?? $data['content_encoding'] ?? 'aesgcm';
        $subscription = $user->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $encoding,
        );

        return ApiResponse::success([
            'id' => $subscription->id,
            'endpoint' => $subscription->endpoint,
        ], 'Suscripción guardada');
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);
        $user->deletePushSubscription($data['endpoint']);

        return ApiResponse::success(null, 'Suscripción eliminada');
    }
}
