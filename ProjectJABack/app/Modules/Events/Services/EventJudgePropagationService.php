<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventJudgePropagationService
{
    /**
     * Agrega jueces nuevos a los hijos sin juez. Si un hijo ya tiene juez, no lo pisa
     * y devuelve el conflicto para que el usuario decida.
     *
     * @param  list<int>  $addedJuezIds
     * @return list<array<string, mixed>>
     */
    public function propagateAdded(Event $parent, array $addedJuezIds): array
    {
        $addedJuezIds = array_values(array_unique(array_map('intval', $addedJuezIds)));
        if ($addedJuezIds === []) {
            return [];
        }

        $descendantIds = $this->descendantEventIds((int) $parent->id);
        if ($descendantIds === []) {
            return [];
        }

        $incoming = User::query()
            ->whereIn('id', $addedJuezIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $children = Event::query()
            ->whereIn('id', $descendantIds)
            ->with(['jueces:id,name,email'])
            ->get();

        $conflicts = [];
        foreach ($children as $child) {
            $own = $child->ownJueces()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $incomingNew = array_values(array_diff($addedJuezIds, $own));
            if ($incomingNew === []) {
                continue;
            }
            if ($own === []) {
                $child->jueces()->syncWithoutDetaching($incomingNew);

                continue;
            }

            $conflicts[] = [
                'id' => (int) $child->id,
                'name' => $child->name,
                'juez_ids' => $own,
                'jueces' => $child->ownJueces()->map(fn (User $user) => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])->values()->all(),
                'incoming_juez_ids' => $incomingNew,
                'incoming_jueces' => collect($incomingNew)
                    ->map(fn (int $id) => $incoming->get($id))
                    ->filter()
                    ->map(fn (User $user) => [
                        'id' => (int) $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $conflicts;
    }

    /**
     * @param  list<int>  $incomingJuezIds
     * @param  list<array{event_id: int, action: string}>  $decisions
     */
    public function resolve(Event $parent, array $incomingJuezIds, array $decisions): void
    {
        $descendantIds = array_flip($this->descendantEventIds((int) $parent->id));
        if ($descendantIds === []) {
            return;
        }

        $incomingJuezIds = array_values(array_unique(array_map('intval', $incomingJuezIds)));
        $parentJuezIds = $parent->ownJueces()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        DB::transaction(function () use ($decisions, $descendantIds, $incomingJuezIds, $parentJuezIds) {
            foreach ($decisions as $decision) {
                $childId = (int) ($decision['event_id'] ?? 0);
                $action = (string) ($decision['action'] ?? '');
                if ($childId <= 0 || ! isset($descendantIds[$childId])) {
                    throw ValidationException::withMessages([
                        'decisions' => ['Hay subeventos que no pertenecen a este evento.'],
                    ]);
                }
                if (! in_array($action, ['replace', 'keep_both', 'keep_existing'], true)) {
                    throw ValidationException::withMessages([
                        'decisions' => ['La acción de jueces no es válida.'],
                    ]);
                }
                if ($action === 'keep_existing') {
                    continue;
                }

                $child = Event::query()->find($childId);
                if (! $child) {
                    continue;
                }
                if ($action === 'replace') {
                    $child->jueces()->sync($parentJuezIds);

                    continue;
                }
                if ($incomingJuezIds !== []) {
                    $child->jueces()->syncWithoutDetaching($incomingJuezIds);
                }
            }
        });
    }

    /**
     * @return list<int>
     */
    private function descendantEventIds(int $rootId): array
    {
        $ids = [];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $children = Event::query()
                ->whereIn('evento_padre_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($children === []) {
                break;
            }
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return array_values(array_unique($ids));
    }
}
