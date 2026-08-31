<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_terreno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terreno_id')->constrained('terrenos')->cascadeOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('es_default')->default(false);
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 30)->default('activo');
            $table->timestamps();
            $table->unique(['terreno_id', 'nombre']);
        });

        // Una config base por terreno existente
        $terrenoIds = DB::table('terrenos')->pluck('id');
        foreach ($terrenoIds as $terrenoId) {
            DB::table('configuraciones_terreno')->insert([
                'terreno_id' => $terrenoId,
                'nombre' => 'Configuración base',
                'descripcion' => 'Distribución inicial de zonas y lotes',
                'es_default' => 1,
                'orden' => 1,
                'estado' => 'activo',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('zonas_terreno', function (Blueprint $table) {
            $table->foreignId('configuracion_terreno_id')->nullable()->after('id')
                ->constrained('configuraciones_terreno')->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('
                UPDATE zonas_terreno z
                INNER JOIN configuraciones_terreno c ON c.terreno_id = z.terreno_id AND c.es_default = 1
                SET z.configuracion_terreno_id = c.id
            ');
        }

        Schema::table('zonas_terreno', function (Blueprint $table) {
            $table->dropForeign(['terreno_id']);
            $table->dropColumn('terreno_id');
        });

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->foreignId('configuracion_terreno_id')->nullable()->after('id')
                ->constrained('configuraciones_terreno')->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('
                UPDATE lotes_terreno l
                INNER JOIN zonas_terreno z ON z.id = l.zona_terreno_id
                SET l.configuracion_terreno_id = z.configuracion_terreno_id
                WHERE l.configuracion_terreno_id IS NULL AND l.zona_terreno_id IS NOT NULL
            ');

            DB::statement('
                UPDATE lotes_terreno l
                INNER JOIN configuraciones_terreno c ON c.terreno_id = l.terreno_id AND c.es_default = 1
                SET l.configuracion_terreno_id = c.id
                WHERE l.configuracion_terreno_id IS NULL
            ');
        }

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->dropForeign(['terreno_id']);
            $table->dropUnique(['terreno_id', 'codigo']);
            $table->dropColumn('terreno_id');
            $table->unique(['configuracion_terreno_id', 'codigo']);
        });

        Schema::table('eventos_terrenos', function (Blueprint $table) {
            $table->foreignId('configuracion_terreno_id')->nullable()->after('terreno_id')
                ->constrained('configuraciones_terreno')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eventos_terrenos', function (Blueprint $table) {
            $table->dropForeign(['configuracion_terreno_id']);
            $table->dropColumn('configuracion_terreno_id');
        });

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->dropUnique(['configuracion_terreno_id', 'codigo']);
            $table->foreignId('terreno_id')->nullable()->after('id')->constrained('terrenos')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE lotes_terreno l
            INNER JOIN configuraciones_terreno c ON c.id = l.configuracion_terreno_id
            SET l.terreno_id = c.terreno_id
        ');

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->dropForeign(['configuracion_terreno_id']);
            $table->dropColumn('configuracion_terreno_id');
            $table->unique(['terreno_id', 'codigo']);
        });

        Schema::table('zonas_terreno', function (Blueprint $table) {
            $table->foreignId('terreno_id')->nullable()->after('id')->constrained('terrenos')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE zonas_terreno z
            INNER JOIN configuraciones_terreno c ON c.id = z.configuracion_terreno_id
            SET z.terreno_id = c.terreno_id
        ');

        Schema::table('zonas_terreno', function (Blueprint $table) {
            $table->dropForeign(['configuracion_terreno_id']);
            $table->dropColumn('configuracion_terreno_id');
        });

        Schema::dropIfExists('configuraciones_terreno');
    }
};
