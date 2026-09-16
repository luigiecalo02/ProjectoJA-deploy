<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            return;
        }

        $page = DB::table('pages')->where('key', 'settings')->first();
        if (! $page) {
            return;
        }

        $front = in_array((string) ($page->front ?? ''), ['project', 'ambos'], true)
            ? $page->front
            : 'project';

        DB::table('pages')->where('id', $page->id)->update([
            'route_name' => 'settings.platform',
            'front' => $front,
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        //
    }
};
