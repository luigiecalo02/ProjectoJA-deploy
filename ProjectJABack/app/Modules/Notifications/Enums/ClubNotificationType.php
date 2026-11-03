<?php

namespace App\Modules\Notifications\Enums;

enum ClubNotificationType: string
{
    case ASISTENCIA_MARCADA = 'asistencia_marcada';
    case ABONO_APROBADO = 'abono_aprobado';
    case ABONO_COMPROBANTE_SUBIDO = 'abono_comprobante_subido';

    public function label(): string
    {
        return match ($this) {
            self::ASISTENCIA_MARCADA => 'Asistencia marcada',
            self::ABONO_APROBADO => 'Abono aprobado',
            self::ABONO_COMPROBANTE_SUBIDO => 'Comprobante de abono',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ASISTENCIA_MARCADA => 'Avisa al integrante cuando su asistencia en un evento cambia.',
            self::ABONO_APROBADO => 'Avisa al integrante cuando la directiva aprueba un comprobante pendiente.',
            self::ABONO_COMPROBANTE_SUBIDO => 'Avisa a la directiva cuando un integrante sube un comprobante pendiente de revisión.',
        };
    }

    public function audience(): string
    {
        return match ($this) {
            self::ABONO_COMPROBANTE_SUBIDO => 'directiva',
            default => 'integrante',
        };
    }

    /**
     * @return list<self>
     */
    public static function catalog(): array
    {
        return self::cases();
    }
}
