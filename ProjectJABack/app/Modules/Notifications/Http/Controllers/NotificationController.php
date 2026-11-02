<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

final class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $perPage = min(50, max(1, (int) $request->integer('per_page', 20)));
        $paginator = $user->notifications()->latest()->paginate($perPage);

        $items = collect($paginator->items())->map(fn (DatabaseNotification $row) => $this->present($row))->all();

        return ApiResponse::success([
            'items' => $items,
            'unread_count' => $user->unreadNotifications()->count(),
        ], 'OK', 200, [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        /** @var DatabaseNotification|null $notification */
        $notification = $user->notifications()->where('id', $id)->first();
        abort_unless($notification !== null, 404, 'Notificación no encontrada.');

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return ApiResponse::success([
            'item' => $this->present($notification->fresh() ?? $notification),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        $user->unreadNotifications->markAsRead();

        return ApiResponse::success([
            'unread_count' => 0,
        ], 'Notificaciones marcadas como leídas');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];

        return [
            'id' => $notification->id,
            'tipo' => $data['tipo'] ?? null,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'url' => $data['url'] ?? '/eventos',
            'icon' => $data['icon'] ?? '/pwa-192x192.png',
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'data' => $data,
        ];
    }
}
