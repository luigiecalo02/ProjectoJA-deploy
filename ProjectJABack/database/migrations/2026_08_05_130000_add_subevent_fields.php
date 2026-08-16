<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categoria_subevento', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('slug', 120)->unique();
            $table->string('color', 32)->nullable();
            $table->string('icono', 64)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('estado')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedInteger('orden')->default(0)->after('evento_padre_id');
            $table->foreignId('categoria_subevento_id')
                ->nullable()
                ->after('tipo_evento_id')
                ->constrained('categoria_subevento')
                ->nullOnDelete();
            $table->unsignedInteger('tiempo_estimado_minutos')->nullable()->after('puntaje_maximo');
            $table->unsignedInteger('participantes_min')->nullable()->after('tiempo_estimado_minutos');
            $table->unsignedInteger('participantes_max')->nullable()->after('participantes_min');
            $table->unsignedInteger('equipos_org_min')->nullable()->after('participantes_max');
            $table->unsignedInteger('equipos_org_max')->nullable()->after('equipos_org_min');
            $table->text('reglas')->nullable()->after('descripcion');
            $table->index(['evento_padre_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['evento_padre_id', 'orden']);
            $table->dropConstrainedForeignId('categoria_subevento_id');
            $table->dropColumn([
                'orden',
                'tiempo_estimado_minutos',
                'participantes_min',
                'participantes_max',
                'equipos_org_min',
                'equipos_org_max',
                'reglas',
            ]);
        });

        Schema::dropIfExists('categoria_subevento');
    }
};
