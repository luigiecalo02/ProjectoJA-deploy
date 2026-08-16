<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return (int) $user->id === $id;
});

/**
 * Canal privado de organizaciones: solo usuarios con permiso de ver el módulo.
 * Las páginas del front se suscriben solo cuando lo necesitan (opt-in).
 */
Broadcast::channel('organizations', function (User $user) {
    return $user->can('viewAny', Organizacion::class);
});

Broadcast::channel('events.{eventId}.distribution', function (User $user, int $eventId) {
    $event = Event::query()->find($eventId);

    return $event && $user->can('view', $event);
});

Broadcast::channel('events.{eventId}.cabanas', function (User $user, int $eventId) {
    $event = Event::query()->find($eventId);

    return $event && (
        $user->can('view', $event)
        || $user->hasPermission('cabanas.view')
        || $user->hasPermission('cabanas.self_assign')
    );
});
