<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('precio_fuera_tiempo', 12, 2)->nullable()->after('precio');
            $table->decimal('precio_acompanante_fuera_tiempo', 12, 2)->nullable()->after('precio_acompanante');
            $table->decimal('precio_acompanante_menor_fuera_tiempo', 12, 2)->nullable()->after('precio_acompanante_menor');
            $table->decimal('precio_directiva_fuera_tiempo', 12, 2)->nullable()->after('precio_directiva');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'precio_fuera_tiempo',
                'precio_acompanante_fuera_tiempo',
                'precio_acompanante_menor_fuera_tiempo',
                'precio_directiva_fuera_tiempo',
            ]);
        });
    }
};
