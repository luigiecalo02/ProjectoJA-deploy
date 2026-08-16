<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            OrganizacionCatalogSeeder::class,
            TipoEventoSeeder::class,
            CategoriaSubeventoSeeder::class,
            UbicacionSeeder::class,
        ]);

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@projectja.local'],
            [
                'name' => 'Administrador',
                'password' => 'Admin123!',
                'is_active' => true,
                'is_super' => true,
                'is_admin' => false,
                'email_verified_at' => now(),
            ]
        );

        $admin->clearPermissionCache();
    }
}
