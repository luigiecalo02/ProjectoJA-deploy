<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('precio_acompanante', 12, 2)->nullable()->after('precio');
            $table->decimal('precio_acompanante_menor', 12, 2)->nullable()->after('precio_acompanante');
            $table->decimal('precio_directiva', 12, 2)->nullable()->after('precio_acompanante_menor');
            $table->json('descuentos_directiva')->nullable()->after('precio_directiva');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'precio_acompanante',
                'precio_acompanante_menor',
                'precio_directiva',
                'descuentos_directiva',
            ]);
        });
    }
};
