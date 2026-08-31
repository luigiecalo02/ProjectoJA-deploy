<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_seguro', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('tipo', 20); // ANUAL | EVENTO
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('duracion_dias')->nullable();
            $table->boolean('requiere_evento')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->index('tipo');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->boolean('requiere_seguro')->default(false)->after('metodo_pago');
            $table->foreignId('tipo_seguro_id')
                ->nullable()
                ->after('requiere_seguro')
                ->constrained('tipos_seguro')
                ->nullOnDelete();
            $table->decimal('seguro_valor', 12, 2)->nullable()->after('tipo_seguro_id');
            $table->date('seguro_fecha_inicio')->nullable()->after('seguro_valor');
            $table->date('seguro_fecha_fin')->nullable()->after('seguro_fecha_inicio');
        });

        Schema::table('evento_inscripcion', function (Blueprint $table) {
            $table->decimal('total_declarado', 12, 2)->nullable()->after('estado');
            $table->foreignId('revisado_por')->nullable()->after('inscrito_por')->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_at')->nullable()->after('revisado_por');
            $table->text('observacion_revision')->nullable()->after('revisado_at');
        });

        // Migrar estados legacy a ciclo de revisión
        DB::table('evento_inscripcion')->where('estado', 'confirmada')->update(['estado' => 'aprobada']);
        DB::table('evento_inscripcion')->where('estado', 'pendiente')->update(['estado' => 'pendiente_revision']);

        Schema::create('evento_inscripcion_persona', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('evento_inscripcion')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->decimal('valor_inscripcion', 12, 2)->default(0);
            $table->string('estado', 32)->default('confirmada')->index();
            $table->timestamps();
            $table->unique(['inscripcion_id', 'persona_id'], 'eip_inscripcion_persona_unique');
        });

        Schema::create('seguros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('tipo_seguro_id')->constrained('tipos_seguro')->restrictOnDelete();
            $table->foreignId('evento_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('inscripcion_id')->nullable()->constrained('evento_inscripcion')->nullOnDelete();
            $table->decimal('valor', 12, 2)->default(0);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('estado', 32)->default('pendiente')->index();
            $table->string('referencia_pago', 128)->nullable();
            $table->timestamp('fecha_pago')->nullable();
            $table->timestamps();
            $table->index(['persona_id', 'estado']);
            $table->index(['evento_id', 'persona_id']);
        });

        Schema::create('evento_inscripcion_comprobante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('evento_inscripcion')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->decimal('valor', 12, 2);
            $table->string('estado', 32)->default('pendiente')->index();
            $table->text('observacion')->nullable();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_at')->nullable();
            $table->timestamps();
        });

        Schema::table('evento_pago', function (Blueprint $table) {
            $table->string('pagable_type')->nullable()->after('id');
            $table->unsignedBigInteger('pagable_id')->nullable()->after('pagable_type');
            $table->string('referencia', 128)->nullable()->after('pagado_at');
            $table->index(['pagable_type', 'pagable_id']);
        });

        // Hacer inscripcion_id nullable (agrupador opcional) sin doctrine/dbal change()
        Schema::table('evento_pago', function (Blueprint $table) {
            $table->dropForeign(['inscripcion_id']);
        });
        Schema::table('evento_pago', function (Blueprint $table) {
            $table->unsignedBigInteger('inscripcion_id')->nullable()->change();
        });
        Schema::table('evento_pago', function (Blueprint $table) {
            $table->foreign('inscripcion_id')->references('id')->on('evento_inscripcion')->nullOnDelete();
        });

        Schema::create('productos_servicios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('tipo', 64)->index();
            $table->text('descripcion')->nullable();
            $table->decimal('precio', 12, 2)->default(0);
            $table->string('unidad', 32)->default('UNIDAD');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('evento_producto_servicio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('producto_servicio_id')->constrained('productos_servicios')->restrictOnDelete();
            $table->decimal('precio', 12, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['evento_id', 'producto_servicio_id'], 'eps_evento_producto_unique');
        });

        Schema::create('evento_servicio_reserva', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('evento_producto_servicio_id')->constrained('evento_producto_servicio')->restrictOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('inscripcion_id')->nullable()->constrained('evento_inscripcion')->nullOnDelete();
            $table->decimal('precio_unitario', 12, 2)->default(0);
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('valor_total', 12, 2)->default(0);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->unsignedInteger('cantidad_dias')->nullable();
            $table->decimal('precio_dia', 12, 2)->nullable();
            $table->date('fecha')->nullable();
            $table->string('estado', 32)->default('reservada')->index();
            $table->timestamps();
            $table->index(['evento_id', 'persona_id']);
        });

        // Seed tipos seguro base
        $now = now();
        DB::table('tipos_seguro')->insert([
            [
                'nombre' => 'Seguro anual',
                'tipo' => 'ANUAL',
                'descripcion' => 'Cobertura anual vigente para múltiples eventos',
                'duracion_dias' => 365,
                'requiere_evento' => false,
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nombre' => 'Seguro de evento',
                'tipo' => 'EVENTO',
                'descripcion' => 'Cobertura específica para un evento',
                'duracion_dias' => null,
                'requiere_evento' => true,
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Seed productos base
        DB::table('productos_servicios')->insert([
            [
                'nombre' => 'Pasadía',
                'tipo' => 'PASADIA',
                'descripcion' => 'Acceso de un día al evento',
                'precio' => 25000,
                'unidad' => 'DIA',
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nombre' => 'Cabaña',
                'tipo' => 'CABANA',
                'descripcion' => 'Hospedaje por día',
                'precio' => 80000,
                'unidad' => 'DIA',
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nombre' => 'Alimentación',
                'tipo' => 'ALIMENTACION',
                'descripcion' => 'Servicio de alimentación',
                'precio' => 0,
                'unidad' => 'UNIDAD',
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'nombre' => 'Parqueadero',
                'tipo' => 'PARQUEADERO',
                'descripcion' => 'Parqueo por día',
                'precio' => 10000,
                'unidad' => 'DIA',
                'activo' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_servicio_reserva');
        Schema::dropIfExists('evento_producto_servicio');
        Schema::dropIfExists('productos_servicios');
        Schema::dropIfExists('evento_inscripcion_comprobante');
        Schema::dropIfExists('seguros');
        Schema::dropIfExists('evento_inscripcion_persona');

        Schema::table('evento_pago', function (Blueprint $table) {
            $table->dropIndex(['pagable_type', 'pagable_id']);
            $table->dropColumn(['pagable_type', 'pagable_id', 'referencia']);
        });

        Schema::table('evento_inscripcion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revisado_por');
            $table->dropColumn(['total_declarado', 'revisado_at', 'observacion_revision']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_seguro_id');
            $table->dropColumn([
                'requiere_seguro',
                'seguro_valor',
                'seguro_fecha_inicio',
                'seguro_fecha_fin',
            ]);
        });

        Schema::dropIfExists('tipos_seguro');
    }
};
