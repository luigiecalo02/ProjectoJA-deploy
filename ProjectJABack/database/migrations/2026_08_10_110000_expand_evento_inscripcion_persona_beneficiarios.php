<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_inscripcion_persona', function (Blueprint $table) {
            $table->dropForeign('eip_persona_id_foreign');
        });

        DB::statement('ALTER TABLE evento_inscripcion_persona MODIFY persona_id BIGINT UNSIGNED NULL');

        Schema::table('evento_inscripcion_persona', function (Blueprint $table) {
            $table->foreign('persona_id')->references('id')->on('personas')->nullOnDelete();
            $table->string('tipo', 32)->default('miembro')->after('persona_id');
            $table->string('referencia_cliente', 100)->nullable()->after('tipo');
            $table->string('nombre_snapshot')->nullable()->after('referencia_cliente');
            $table->string('identificacion_snapshot', 64)->nullable()->after('nombre_snapshot');
            $table->date('fecha_nacimiento_snapshot')->nullable()->after('identificacion_snapshot');
            $table->string('parentesco', 100)->nullable()->after('fecha_nacimiento_snapshot');
            $table->string('descuento_codigo', 64)->nullable()->after('parentesco');
            $table->string('descuento_nombre', 120)->nullable()->after('descuento_codigo');
            $table->decimal('descuento_porcentaje', 5, 2)->default(0)->after('descuento_nombre');
            $table->decimal('valor_base', 12, 2)->default(0)->after('descuento_porcentaje');
            $table->decimal('valor_descuento', 12, 2)->default(0)->after('valor_base');
            $table->decimal('valor_seguro', 12, 2)->default(0)->after('valor_inscripcion');
            $table->index(['inscripcion_id', 'tipo'], 'eip_inscripcion_tipo_index');
            $table->unique(
                ['inscripcion_id', 'referencia_cliente'],
                'eip_inscripcion_referencia_unique'
            );
        });

        DB::table('evento_inscripcion_persona')->update([
            'tipo' => 'miembro',
            'valor_base' => DB::raw('valor_inscripcion'),
        ]);

        Schema::table('evento_servicio_reserva', function (Blueprint $table) {
            $table->dropForeign('esr_persona_id_foreign');
        });

        DB::statement('ALTER TABLE evento_servicio_reserva MODIFY persona_id BIGINT UNSIGNED NULL');

        Schema::table('evento_servicio_reserva', function (Blueprint $table) {
            $table->foreign('persona_id')->references('id')->on('personas')->nullOnDelete();
            $table->foreignId('inscripcion_persona_id')
                ->nullable()
                ->after('persona_id')
                ->constrained('evento_inscripcion_persona')
                ->cascadeOnDelete();
        });

        DB::statement(
            'UPDATE evento_servicio_reserva r
             INNER JOIN evento_inscripcion_persona ip
                ON ip.inscripcion_id = r.inscripcion_id
               AND ip.persona_id = r.persona_id
             SET r.inscripcion_persona_id = ip.id
             WHERE r.inscripcion_persona_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('evento_servicio_reserva', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inscripcion_persona_id');
        });

        Schema::table('evento_inscripcion_persona', function (Blueprint $table) {
            $table->dropUnique('eip_inscripcion_referencia_unique');
            $table->dropIndex('eip_inscripcion_tipo_index');
            $table->dropColumn([
                'tipo',
                'referencia_cliente',
                'nombre_snapshot',
                'identificacion_snapshot',
                'fecha_nacimiento_snapshot',
                'parentesco',
                'descuento_codigo',
                'descuento_nombre',
                'descuento_porcentaje',
                'valor_base',
                'valor_descuento',
                'valor_seguro',
            ]);
        });
    }
};
