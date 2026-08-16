<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_identificacion', 30);
            $table->string('identificacion', 50);
            $table->string('nombre1');
            $table->string('nombre2')->nullable();
            $table->string('apellido1');
            $table->string('apellido2')->nullable();
            $table->date('fecha_nacimiento')->nullable();
            $table->string('sexo', 20)->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('correo')->nullable();
            $table->string('direccion_actual')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tipo_identificacion', 'identificacion']);
            $table->index('correo');
        });

        Schema::create('clubes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('logo_url')->nullable();
            $table->string('distrito')->nullable();
            $table->string('ciudad')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('club_persona', function (Blueprint $table) {
            $table->foreignId('club_id')->constrained('clubes')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('cargo')->nullable();
            $table->timestamps();
            $table->primary(['club_id', 'persona_id']);
        });

        Schema::create('club_directors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubes')->cascadeOnDelete();
            $table->string('ministry', 40);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['club_id', 'ministry']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_directors');
        Schema::dropIfExists('club_persona');
        Schema::dropIfExists('clubes');
        Schema::dropIfExists('personas');
    }
};
