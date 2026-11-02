<?php

namespace App\Modules\Notifications\Enums;

enum ClubNotificationType: string
{
    case ASISTENCIA_MARCADA = 'asistencia_marcada';
    case ABONO_APROBADO = 'abono_aprobado';

    public function label(): string
    {
        return match ($this) {
            self::ASISTENCIA_MARCADA => 'Asistencia marcada',
            self::ABONO_APROBADO => 'Abono aprobado',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ASISTENCIA_MARCADA => 'Avisa al integrante cuando su asistencia en un evento cambia.',
            self::ABONO_APROBADO => 'Avisa al integrante cuando la directiva aprueba un comprobante pendiente.',
        };
    }

    public function audience(): string
    {
        return 'integrante';
    }

    /**
     * @return list<self>
     */
    public static function catalog(): array
    {
        return self::cases();
    }
}
