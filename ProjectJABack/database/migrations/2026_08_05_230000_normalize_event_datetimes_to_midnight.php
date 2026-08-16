<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("UPDATE events SET starts_at = datetime(date(starts_at) || ' 00:00:00')");
            DB::statement("UPDATE events SET ends_at = datetime(date(ends_at) || ' 00:00:00')");
            DB::statement("UPDATE events SET fecha_limite_pago = datetime(date(fecha_limite_pago) || ' 00:00:00') WHERE fecha_limite_pago IS NOT NULL");

            return;
        }

        DB::statement("UPDATE events SET starts_at = CONCAT(DATE(starts_at), ' 00:00:00')");
        DB::statement("UPDATE events SET ends_at = CONCAT(DATE(ends_at), ' 00:00:00')");
        DB::statement("UPDATE events SET fecha_limite_pago = CONCAT(DATE(fecha_limite_pago), ' 00:00:00') WHERE fecha_limite_pago IS NOT NULL");
    }

    public function down(): void
    {
        // No se restaura la hora original.
    }
};
