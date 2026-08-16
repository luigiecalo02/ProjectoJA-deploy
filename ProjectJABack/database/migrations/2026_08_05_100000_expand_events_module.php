<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('evento_padre_id')
                ->nullable()
                ->after('id')
                ->constrained('events')
                ->nullOnDelete();
            $table->foreignId('organizacion_id')
                ->nullable()
                ->after('evento_padre_id')
                ->constrained('organizacion')
                ->nullOnDelete();
            $table->text('descripcion')->nullable()->after('name');
            $table->string('lugar')->nullable()->after('descripcion');
            $table->decimal('latitud', 10, 7)->nullable()->after('lugar');
            $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
            $table->string('estado', 32)->default('borrador')->after('is_active')->index();
            $table->boolean('es_en_sitio')->default(true)->after('estado');
            $table->boolean('es_calificable')->default(false)->after('es_en_sitio');
            $table->decimal('puntaje_maximo', 12, 2)->nullable()->after('es_calificable');
            $table->boolean('requiere_pago')->default(false)->after('puntaje_maximo');
            $table->decimal('precio', 12, 2)->nullable()->after('requiere_pago');
            $table->dateTime('fecha_limite_pago')->nullable()->after('precio');
            $table->string('metodo_pago', 64)->nullable()->after('fecha_limite_pago');
            $table->unsignedInteger('cupo_maximo')->nullable()->after('metodo_pago');
            $table->boolean('cupo_ilimitado')->default(false)->after('cupo_maximo');
            $table->unsignedInteger('cupo_max_organizacion')->nullable()->after('cupo_ilimitado');
            $table->unsignedInteger('cupo_max_club')->nullable()->after('cupo_max_organizacion');
            $table->unsignedInteger('cupo_max_iglesia')->nullable()->after('cupo_max_club');
            $table->boolean('permite_inscripcion_individual')->default(true)->after('cupo_max_iglesia');
            $table->boolean('permite_inscripcion_organizacion')->default(false)->after('permite_inscripcion_individual');
            $table->boolean('permite_inscripcion_club')->default(false)->after('permite_inscripcion_organizacion');
            $table->boolean('permite_inscripcion_iglesia')->default(false)->after('permite_inscripcion_club');

            $table->index('organizacion_id');
            $table->index('evento_padre_id');
        });

        Schema::create('evento_organizacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['evento_id', 'organizacion_id']);
        });

        Schema::create('evento_tipo_organizacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('tipo_organizacion_id')->constrained('tipo_organizacion')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['evento_id', 'tipo_organizacion_id'], 'evento_tipo_org_unique');
        });

        Schema::create('evento_archivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('file_id')->constrained('files')->cascadeOnDelete();
            $table->string('tipo', 32);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->index(['evento_id', 'tipo']);
        });

        Schema::create('evento_inscripcion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->string('tipo', 32);
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('organizacion_id')->nullable()->constrained('organizacion')->nullOnDelete();
            $table->string('estado', 32)->default('pendiente')->index();
            $table->foreignId('inscrito_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['evento_id', 'tipo']);
            $table->index(['evento_id', 'persona_id']);
            $table->index(['evento_id', 'organizacion_id']);
        });

        Schema::create('evento_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('evento_inscripcion')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->string('moneda', 8)->default('COP');
            $table->string('metodo', 64)->nullable();
            $table->string('estado', 32)->default('pendiente')->index();
            $table->dateTime('fecha_limite')->nullable();
            $table->dateTime('pagado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('evento_calificacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('organizacion_id')->nullable()->constrained('organizacion')->nullOnDelete();
            $table->decimal('puntaje_obtenido', 12, 2)->default(0);
            $table->unsignedInteger('puesto')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['evento_id', 'persona_id']);
            $table->index(['evento_id', 'organizacion_id']);
        });

        Schema::dropIfExists('event_role');
    }

    public function down(): void
    {
        Schema::create('event_role', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['event_id', 'role_id']);
        });

        Schema::dropIfExists('evento_calificacion');
        Schema::dropIfExists('evento_pago');
        Schema::dropIfExists('evento_inscripcion');
        Schema::dropIfExists('evento_archivo');
        Schema::dropIfExists('evento_tipo_organizacion');
        Schema::dropIfExists('evento_organizacion');

        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evento_padre_id');
            $table->dropConstrainedForeignId('organizacion_id');
            $table->dropColumn([
                'descripcion',
                'lugar',
                'latitud',
                'longitud',
                'estado',
                'es_en_sitio',
                'es_calificable',
                'puntaje_maximo',
                'requiere_pago',
                'precio',
                'fecha_limite_pago',
                'metodo_pago',
                'cupo_maximo',
                'cupo_ilimitado',
                'cupo_max_organizacion',
                'cupo_max_club',
                'cupo_max_iglesia',
                'permite_inscripcion_individual',
                'permite_inscripcion_organizacion',
                'permite_inscripcion_club',
                'permite_inscripcion_iglesia',
            ]);
        });
    }
};
