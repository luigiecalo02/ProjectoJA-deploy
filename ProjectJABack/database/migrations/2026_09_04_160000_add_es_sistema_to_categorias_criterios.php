<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categoria_subevento') && ! Schema::hasColumn('categoria_subevento', 'es_sistema')) {
            Schema::table('categoria_subevento', function (Blueprint $table) {
                $table->boolean('es_sistema')->default(false)->after('estado');
            });

            DB::table('categoria_subevento')
                ->whereIn('slug', ['especialidades', 'conocimiento', 'habilidades', 'desafios'])
                ->update(['es_sistema' => true]);
        }

        if (Schema::hasTable('criterio_evaluacion') && ! Schema::hasColumn('criterio_evaluacion', 'es_sistema')) {
            Schema::table('criterio_evaluacion', function (Blueprint $table) {
                $table->boolean('es_sistema')->default(false)->after('estado');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categoria_subevento', 'es_sistema')) {
            Schema::table('categoria_subevento', function (Blueprint $table) {
                $table->dropColumn('es_sistema');
            });
        }

        if (Schema::hasColumn('criterio_evaluacion', 'es_sistema')) {
            Schema::table('criterio_evaluacion', function (Blueprint $table) {
                $table->dropColumn('es_sistema');
            });
        }
    }
};
