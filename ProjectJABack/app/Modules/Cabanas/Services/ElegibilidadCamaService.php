<?php

namespace App\Modules\Cabanas\Services;

use App\Models\User;
use App\Modules\Cabanas\Models\EventoCabanaCama;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\EventoPago;
use App\Modules\Events\Models\EventoServicioReserva;
use Illuminate\Validation\ValidationException;

final class ElegibilidadCamaService
{
    /**
     * @return array{eligible: bool, codigo: string, motivo: string|null}
     */
    public function explain(User $actor, Event $event): array
    {
        if (! $actor->persona_id) {
            return [
                'eligible' => false,
                'codigo' => 'sin_persona',
                'motivo' => 'Completa tu perfil de persona para elegir una cama.',
            ];
        }

        $sexo = $actor->persona()->value('sexo');
        if (! in_array($sexo, ['M', 'F'], true)) {
            return [
                'eligible' => false,
                'codigo' => 'sin_sexo',
                'motivo' => 'El perfil debe tener sexo M o F para elegir una cama.',
            ];
        }

        $linea = $this->confirmedLine($actor, $event);
        if (! $linea) {
            return [
                'eligible' => false,
                'codigo' => 'sin_inscripcion',
                'motivo' => 'Tu inscripción debe estar aprobada para elegir una cama.',
            ];
        }

        try {
            $this->resolveForLine($event, $linea);
        } catch (ValidationException) {
            return [
                'eligible' => false,
                'codigo' => 'sin_reserva',
                'motivo' => 'Se requiere reserva de cabaña confirmada y pagada.',
            ];
        }

        return ['eligible' => true, 'codigo' => 'ok', 'motivo' => null];
    }

    public function resolveIndividual(User $actor, Event $event): array
    {
        if (! $actor->persona_id) {
            throw ValidationException::withMessages(['persona' => ['El usuario no está vinculado a una persona.']]);
        }
        $sexo = $actor->persona()->value('sexo');
        if (! in_array($sexo, ['M', 'F'], true)) {
            throw ValidationException::withMessages(['sexo' => ['El perfil debe tener sexo M o F para elegir una cama.']]);
        }
        $linea = $this->confirmedLine($actor, $event);
        if (! $linea) {
            throw ValidationException::withMessages(['inscripcion' => ['La persona no tiene inscripción aprobada en el evento.']]);
        }

        return $this->resolveForLine($event, $linea);
    }

    private function confirmedLine(User $actor, Event $event): ?EventoInscripcionPersona
    {
        return EventoInscripcionPersona::query()
            ->where('persona_id', $actor->persona_id)
            ->where('estado', EventoInscripcionPersona::ESTADO_CONFIRMADA)
            ->whereHas('inscripcion', fn ($q) => $q->where('evento_id', $event->id)
                ->where('estado', EventoInscripcion::ESTADO_APROBADA))
            ->first();
    }

    public function resolveForLine(Event $event, EventoInscripcionPersona $linea): array
    {
        $inscripcionValida = $linea->estado === EventoInscripcionPersona::ESTADO_CONFIRMADA
            && $linea->inscripcion()
                ->where('evento_id', $event->id)
                ->where('estado', EventoInscripcion::ESTADO_APROBADA)
                ->exists();
        if (! $inscripcionValida) {
            throw ValidationException::withMessages(['inscripcion' => ['La persona no tiene inscripción aprobada en el evento.']]);
        }
        $reserva = EventoServicioReserva::query()
            ->where('evento_id', $event->id)
            ->where('inscripcion_persona_id', $linea->id)
            ->where('estado', EventoServicioReserva::ESTADO_CONFIRMADA)
            ->whereHas('oferta.producto', fn ($q) => $q->where('tipo', 'CABANA'))
            ->whereHas('pagos', fn ($q) => $q->where('estado', EventoPago::ESTADO_PAGADO))
            ->first();
        if (! $reserva) {
            throw ValidationException::withMessages(['reserva' => ['Se requiere reserva CABANA confirmada y pagada.']]);
        }

        return [$linea, $reserva];
    }

    public function assertCompatible(EventoCabanaCama $cama, EventoInscripcionPersona $linea): void
    {
        $cama->loadMissing('cuarto');
        $genero = $cama->cuarto?->genero;
        $sexo = $linea->persona()->value('sexo');
        if (! in_array($sexo, ['M', 'F'], true)) {
            throw ValidationException::withMessages(['sexo' => ['El perfil debe tener sexo M o F para elegir una cama.']]);
        }
        if ($genero !== 'MIXTO' && $genero !== $sexo) {
            throw ValidationException::withMessages(['genero' => ['El sexo de la persona no es compatible con el cuarto.']]);
        }
    }

    public function isEligible(User $actor, Event $event): bool
    {
        return $this->explain($actor, $event)['eligible'];
    }
}
