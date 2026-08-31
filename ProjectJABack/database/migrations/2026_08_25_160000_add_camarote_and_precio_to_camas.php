<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabana_camas', function (Blueprint $table) {
            $table->string('tipo', 24)->default('sencilla');
            $table->string('nivel_camarote', 16)->nullable();
            $table->string('grupo_camarote', 64)->nullable();
            $table->decimal('precio_sugerido', 12, 2)->nullable();
            $table->index(['cabana_cuarto_id', 'grupo_camarote']);
        });

        Schema::table('evento_cabana_camas', function (Blueprint $table) {
            $table->string('tipo', 24)->default('sencilla');
            $table->string('nivel_camarote', 16)->nullable();
            $table->string('grupo_camarote', 64)->nullable();
            $table->decimal('precio_sugerido', 12, 2)->nullable();
            $table->decimal('precio', 12, 2)->nullable();
            $table->index(['evento_cabana_cuarto_id', 'grupo_camarote']);
        });
    }

    public function down(): void
    {
        Schema::table('cabana_camas', function (Blueprint $table) {
            $table->dropIndex(['cabana_cuarto_id', 'grupo_camarote']);
            $table->dropColumn(['tipo', 'nivel_camarote', 'grupo_camarote', 'precio_sugerido']);
        });

        Schema::table('evento_cabana_camas', function (Blueprint $table) {
            $table->dropIndex(['evento_cabana_cuarto_id', 'grupo_camarote']);
            $table->dropColumn(['tipo', 'nivel_camarote', 'grupo_camarote', 'precio_sugerido', 'precio']);
        });
    }
};
