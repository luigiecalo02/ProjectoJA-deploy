<?php

namespace App\Modules\Cabanas\Observers;

use App\Modules\Cabanas\Services\AsignacionCamaService;
use App\Modules\Events\Models\EventoServicioReserva;

final class EventoServicioReservaObserver
{
    public function __construct(private readonly AsignacionCamaService $asignaciones) {}

    public function updated(EventoServicioReserva $reserva): void
    {
        if ($reserva->wasChanged('estado') && $reserva->estado === EventoServicioReserva::ESTADO_CANCELADA) {
            $this->asignaciones->releaseByReservation($reserva);
        }
    }

    public function deleting(EventoServicioReserva $reserva): void
    {
        $this->asignaciones->releaseByReservation($reserva);
    }
}
