<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubes', function (Blueprint $table) {
            $table->foreignId('organizacion_id')
                ->nullable()
                ->after('id')
                ->constrained('organizacion')
                ->restrictOnDelete();
            $table->unique('organizacion_id');

            $table->string('nombre_corto', 100)->nullable()->after('nombre');
            $table->string('lema', 255)->nullable()->after('nombre_corto');
            $table->string('logo', 2048)->nullable()->after('lema');
            $table->date('fecha_fundacion')->nullable()->after('logo');
            $table->text('descripcion')->nullable()->after('fecha_fundacion');
            $table->string('color_principal', 20)->nullable()->after('descripcion');
            $table->string('color_secundario', 20)->nullable()->after('color_principal');
            $table->string('sitio_web', 255)->nullable()->after('color_secundario');
        });

        if (Schema::hasColumn('clubes', 'logo_url')) {
            DB::table('clubes')
                ->whereNotNull('logo_url')
                ->update(['logo' => DB::raw('logo_url')]);

            Schema::table('clubes', function (Blueprint $table) {
                $table->dropColumn('logo_url');
            });
        }
    }

    public function down(): void
    {
        Schema::table('clubes', function (Blueprint $table) {
            $table->string('logo_url')->nullable()->after('nombre');
        });

        DB::table('clubes')
            ->whereNotNull('logo')
            ->update(['logo_url' => DB::raw('logo')]);

        Schema::table('clubes', function (Blueprint $table) {
            $table->dropForeign(['organizacion_id']);
            $table->dropUnique(['organizacion_id']);
            $table->dropColumn([
                'organizacion_id',
                'nombre_corto',
                'lema',
                'logo',
                'fecha_fundacion',
                'descripcion',
                'color_principal',
                'color_secundario',
                'sitio_web',
            ]);
        });
    }
};
