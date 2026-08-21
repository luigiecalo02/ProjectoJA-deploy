<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'permite_inscribir_no_participantes')) {
                $table->boolean('permite_inscribir_no_participantes')->default(false)
                    ->after('participantes_max');
            }
            if (! Schema::hasColumn('events', 'participantes_genero')) {
                $table->string('participantes_genero', 16)->nullable()
                    ->after('permite_inscribir_no_participantes');
            }
            if (! Schema::hasColumn('events', 'participantes_min_m')) {
                $table->unsignedInteger('participantes_min_m')->nullable()
                    ->after('participantes_genero');
            }
            if (! Schema::hasColumn('events', 'participantes_min_f')) {
                $table->unsignedInteger('participantes_min_f')->nullable()
                    ->after('participantes_min_m');
            }
        });

        if (! Schema::hasTable('evento_actividad_participante')) {
            Schema::create('evento_actividad_participante', function (Blueprint $table) {
                $table->id();
                $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
                $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
                $table->foreignId('inscrito_por')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(
                    ['evento_id', 'organizacion_id', 'persona_id'],
                    'evt_act_part_unique'
                );
                $table->index(['evento_id', 'organizacion_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_actividad_participante');

        Schema::table('events', function (Blueprint $table) {
            foreach ([
                'permite_inscribir_no_participantes',
                'participantes_genero',
                'participantes_min_m',
                'participantes_min_f',
            ] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
