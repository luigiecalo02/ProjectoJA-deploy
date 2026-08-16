<?php

namespace Tests\Unit;

use App\Modules\Cabanas\Events\OcupacionCabanaChanged;
use PHPUnit\Framework\TestCase;

class OcupacionCabanaChangedTest extends TestCase
{
    public function test_payload_de_ocupacion_no_expone_datos_personales(): void
    {
        $payload = (new OcupacionCabanaChanged(7, 11, 2, 'assigned'))->broadcastWith();

        $this->assertSame([
            'evento_id' => 7,
            'cama_id' => 11,
            'ocupacion' => 2,
            'action' => 'assigned',
        ], $payload);
        $this->assertArrayNotHasKey('persona_id', $payload);
        $this->assertArrayNotHasKey('nombre', $payload);
    }
}
