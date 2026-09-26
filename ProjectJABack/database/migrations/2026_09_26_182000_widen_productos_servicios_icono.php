<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->resizeIconoColumn(255);
    }

    public function down(): void
    {
        $this->resizeIconoColumn(64);
    }

    private function resizeIconoColumn(int $length): void
    {
        if (! Schema::hasTable('productos_servicios') || ! Schema::hasColumn('productos_servicios', 'icono')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE productos_servicios MODIFY icono VARCHAR({$length}) NULL");
        }
    }
};
