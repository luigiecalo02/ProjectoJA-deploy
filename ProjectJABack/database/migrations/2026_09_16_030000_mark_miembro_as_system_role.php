<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        $existing = DB::table('roles')->where('name', 'miembro')->first();

        if ($existing) {
            DB::table('roles')->where('id', $existing->id)->update([
                'display_name' => $existing->display_name ?: 'Miembro',
                'description' => $existing->description ?: 'Integrante del club con acceso a su organización',
                'is_system' => true,
                'is_super' => false,
                'estado' => true,
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('roles')->insert([
            'name' => 'miembro',
            'display_name' => 'Miembro',
            'description' => 'Integrante del club con acceso a su organización',
            'is_system' => true,
            'is_super' => false,
            'estado' => true,
            'sort_order' => 11,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')->where('name', 'miembro')->update([
            'is_system' => false,
            'updated_at' => now(),
        ]);
    }
};
