<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipo_evento', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('slug', 120)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->string('color', 32)->nullable();
            $table->string('icono', 64)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('estado')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('tipo_evento_id')
                ->nullable()
                ->after('organizacion_id')
                ->constrained('tipo_evento')
                ->nullOnDelete();
            $table->unsignedInteger('cupo_minimo')->nullable()->after('metodo_pago');
            $table->index('tipo_evento_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_evento_id');
            $table->dropColumn('cupo_minimo');
        });

        Schema::dropIfExists('tipo_evento');
    }
};
