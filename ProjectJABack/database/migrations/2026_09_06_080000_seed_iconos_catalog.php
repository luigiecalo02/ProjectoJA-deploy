<?php

use Database\Seeders\IconoCatalogSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('iconos')) {
            return;
        }

        (new IconoCatalogSeeder())->run();
    }

    public function down(): void
    {
        //
    }
};
