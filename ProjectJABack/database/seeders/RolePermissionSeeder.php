<?php

namespace Database\Seeders;

use App\Modules\Users\Models\Page;
use App\Modules\Users\Models\Permission;
use App\Modules\Users\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $pages = [
            [
                'key' => 'dashboard',
                'name' => 'Inicio',
                'route_name' => 'dashboard',
                'icon' => 'pi pi-home',
                'sort_order' => 10,
                'description' => 'Panel de inicio',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver inicio', 'sort_order' => 1],
                ],
            ],
            [
                'key' => 'users',
                'name' => 'Usuarios',
                'route_name' => 'users',
                'icon' => 'pi pi-users',
                'sort_order' => 20,
                'description' => 'Gestión de usuarios',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver usuarios', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear usuarios', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar usuarios', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar usuarios', 'sort_order' => 4],
                    ['action' => 'assign_roles', 'display_name' => 'Asignar roles', 'sort_order' => 5],
                ],
            ],
            [
                'key' => 'roles',
                'name' => 'Roles y permisos',
                'route_name' => 'roles',
                'icon' => 'pi pi-shield',
                'sort_order' => 30,
                'description' => 'Administración de roles y páginas',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver roles', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear roles', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar roles', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar roles', 'sort_order' => 4],
                    ['action' => 'assign_permissions', 'display_name' => 'Asignar permisos', 'sort_order' => 5],
                ],
            ],
            [
                'key' => 'settings',
                'name' => 'Apariencia',
                'route_name' => 'settings.brand',
                'icon' => 'pi pi-palette',
                'sort_order' => 35,
                'description' => 'Imágenes de inicio de sesión y fondos de la plataforma',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver apariencia', 'sort_order' => 1],
                    ['action' => 'update', 'display_name' => 'Actualizar apariencia', 'sort_order' => 2],
                ],
            ],
            [
                'key' => 'events',
                'name' => 'Eventos',
                'route_name' => 'events',
                'icon' => 'pi pi-calendar',
                'sort_order' => 40,
                'description' => 'Gestión de eventos del club',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver eventos', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear eventos', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar eventos', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar eventos', 'sort_order' => 4],
                    ['action' => 'evaluate', 'display_name' => 'Evaluar eventos', 'sort_order' => 5],
                    ['action' => 'view_scores', 'display_name' => 'Ver puntajes', 'sort_order' => 6],
                ],
            ],
            [
                'key' => 'clubs',
                'name' => 'Clubes',
                'route_name' => 'clubs',
                'icon' => 'pi pi-building',
                'sort_order' => 50,
                'description' => 'Registro de clubes, integrantes y directores',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver clubes', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear clubes', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar clubes', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar clubes', 'sort_order' => 4],
                    ['action' => 'manage_members', 'display_name' => 'Gestionar integrantes', 'sort_order' => 5],
                    ['action' => 'manage_directors', 'display_name' => 'Gestionar Directiva del Club', 'sort_order' => 6],
                ],
            ],
            [
                'key' => 'organizaciones',
                'name' => 'Organizaciones',
                'route_name' => 'organizaciones',
                'icon' => 'pi pi-sitemap',
                'sort_order' => 45,
                'description' => 'Jerarquía de organizaciones (Unión, Asociación, Distrito, Iglesia, Club)',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver organizaciones', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear organizaciones', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar organizaciones', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar organizaciones', 'sort_order' => 4],
                ],
            ],
            [
                'key' => 'personas',
                'name' => 'Personas',
                'route_name' => 'personas',
                'icon' => 'pi pi-id-card',
                'sort_order' => 55,
                'description' => 'Registro general de personas (cualquier organización del alcance)',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver personas', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear personas', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar personas', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar personas', 'sort_order' => 4],
                ],
            ],
            [
                'key' => 'integrantes',
                'name' => 'Integrantes',
                'route_name' => 'integrantes',
                'icon' => 'pi pi-users',
                'sort_order' => 56,
                'description' => 'Personas asociadas a clubes (solo organizaciones tipo Club)',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver integrantes', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear integrantes', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar integrantes', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar integrantes', 'sort_order' => 4],
                ],
            ],
            [
                'key' => 'terrenos',
                'name' => 'Terrenos',
                'route_name' => 'terrenos',
                'icon' => 'pi pi-map',
                'sort_order' => 57,
                'description' => 'Administración de terrenos y distribución de espacios en eventos',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver terrenos', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear terrenos', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar terrenos', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar terrenos', 'sort_order' => 4],
                    ['action' => 'assign', 'display_name' => 'Asignar lotes a clubes', 'sort_order' => 5],
                    ['action' => 'override_capacity', 'display_name' => 'Sobreasignar capacidad', 'sort_order' => 6],
                ],
            ],
            [
                'key' => 'cabanas',
                'name' => 'Cabañas',
                'route_name' => 'cabanas',
                'icon' => 'pi pi-home',
                'sort_order' => 58,
                'description' => 'Administración de cabañas, croquis y camas por evento',
                'permissions' => [
                    ['action' => 'view', 'display_name' => 'Ver cabañas', 'sort_order' => 1],
                    ['action' => 'create', 'display_name' => 'Crear cabañas', 'sort_order' => 2],
                    ['action' => 'update', 'display_name' => 'Actualizar cabañas', 'sort_order' => 3],
                    ['action' => 'delete', 'display_name' => 'Eliminar cabañas', 'sort_order' => 4],
                    ['action' => 'assign', 'display_name' => 'Asignar camas', 'sort_order' => 5],
                    ['action' => 'self_assign', 'display_name' => 'Elegir cama propia', 'sort_order' => 6],
                ],
            ],
        ];

        foreach ($pages as $pageData) {
            $permissions = $pageData['permissions'];
            unset($pageData['permissions']);

            $page = Page::query()->updateOrCreate(
                ['key' => $pageData['key']],
                $pageData
            );

            foreach ($permissions as $perm) {
                $name = "{$page->key}.{$perm['action']}";
                Permission::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'display_name' => $perm['display_name'],
                        'module' => $page->key,
                        'page_id' => $page->id,
                        'action' => $perm['action'],
                        'sort_order' => $perm['sort_order'],
                    ]
                );
            }
        }

        $systemRoles = [
            [
                'name' => 'super_admin',
                'display_name' => 'Super Administrador',
                'description' => 'Acceso total a todas las páginas y permisos',
                'is_super' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrador',
                'description' => 'Administración general del sistema',
                'is_super' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'supervisor',
                'display_name' => 'Supervisor',
                'description' => 'Consulta puntajes de eventos sin poder editar ni calificar',
                'is_super' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'pastor',
                'display_name' => 'Pastor',
                'description' => 'Cuenta pastoral: asocia y consulta sus clubes',
                'is_super' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'juez',
                'display_name' => 'Juez',
                'description' => 'Evalúa y califica eventos y subeventos',
                'is_super' => false,
                'sort_order' => 5,
            ],
            [
                'name' => 'director',
                'display_name' => 'Director',
                'description' => 'Dirección de club / ministerio',
                'is_super' => false,
                'sort_order' => 6,
            ],
            [
                'name' => 'subdirector',
                'display_name' => 'Subdirector',
                'description' => 'Apoya la dirección del club y asume en su ausencia',
                'is_super' => false,
                'sort_order' => 7,
            ],
            [
                'name' => 'secretario',
                'display_name' => 'Secretario',
                'description' => 'Secretaría',
                'is_super' => false,
                'sort_order' => 8,
            ],
            [
                'name' => 'tesorero',
                'display_name' => 'Tesorero',
                'description' => 'Tesorería',
                'is_super' => false,
                'sort_order' => 9,
            ],
            [
                'name' => 'invitado',
                'display_name' => 'Invitado',
                'description' => 'Acceso mínimo de solo lectura (creado por el sistema)',
                'is_super' => false,
                'sort_order' => 10,
            ],
        ];

        foreach ($systemRoles as $roleData) {
            Role::query()->updateOrCreate(
                ['name' => $roleData['name']],
                [
                    'display_name' => $roleData['display_name'],
                    'description' => $roleData['description'],
                    'is_system' => true,
                    'is_super' => $roleData['is_super'],
                    'estado' => true,
                    'sort_order' => $roleData['sort_order'],
                ]
            );
        }

        $this->migrateLegacyRoles();

        $super = Role::query()->where('name', 'super_admin')->firstOrFail();
        $admin = Role::query()->where('name', 'admin')->firstOrFail();
        $director = Role::query()->where('name', 'director')->firstOrFail();
        $subdirector = Role::query()->where('name', 'subdirector')->firstOrFail();
        $secretario = Role::query()->where('name', 'secretario')->firstOrFail();
        $tesorero = Role::query()->where('name', 'tesorero')->firstOrFail();
        $pastor = Role::query()->where('name', 'pastor')->firstOrFail();
        $invitado = Role::query()->where('name', 'invitado')->firstOrFail();
        $juez = Role::query()->where('name', 'juez')->firstOrFail();
        $supervisor = Role::query()->where('name', 'supervisor')->firstOrFail();

        $allPermissionIds = Permission::query()->pluck('id');
        $admin->permissions()->sync($allPermissionIds);
        $super->permissions()->sync([]);

        $directorPermissions = Permission::query()->whereIn('name', [
            'dashboard.view',
            'events.view',
            'clubs.view',
            'clubs.update',
            'clubs.manage_members',
            'clubs.manage_directors',
            'personas.view',
            'personas.create',
            'personas.update',
            'integrantes.view',
            'integrantes.create',
            'integrantes.update',
        ])->pluck('id');

        $director->permissions()->sync($directorPermissions);
        $subdirector->permissions()->sync($directorPermissions);

        $secretario->permissions()->sync(
            Permission::query()->whereIn('name', [
                'dashboard.view',
                'events.view',
                'clubs.view',
                'personas.view',
                'personas.create',
                'personas.update',
                'integrantes.view',
                'integrantes.create',
                'integrantes.update',
            ])->pluck('id')
        );

        $tesorero->permissions()->sync(
            Permission::query()->whereIn('name', [
                'dashboard.view',
                'events.view',
                'clubs.view',
                'personas.view',
                'integrantes.view',
            ])->pluck('id')
        );

        $pastor->permissions()->sync(
            Permission::query()->whereIn('name', [
                'dashboard.view',
                'events.view',
                'clubs.view',
                'personas.view',
                'personas.create',
                'personas.update',
                'integrantes.view',
                'integrantes.create',
                'integrantes.update',
            ])->pluck('id')
        );

        $invitado->permissions()->sync(
            Permission::query()->whereIn('name', [
                'dashboard.view',
                'events.view',
                'cabanas.view',
                'cabanas.self_assign',
            ])->pluck('id')
        );

        $juez->permissions()->sync(
            Permission::query()->whereIn('name', [
                'dashboard.view',
                'events.view',
                'events.evaluate',
                'events.view_scores',
            ])->pluck('id')
        );

        $supervisor->permissions()->sync(
            Permission::query()->whereIn('name', [
                'dashboard.view',
                'events.view',
                'events.view_scores',
            ])->pluck('id')
        );
    }

    /**
     * Reasigna usuarios/permisos de roles antiguos y elimina los que ya no existen.
     */
    private function migrateLegacyRoles(): void
    {
        $legacyMap = [
            'iglesia' => 'pastor',
            'club' => 'pastor',
            'integrante' => 'pastor',
            'user' => 'pastor',
            'operator' => 'admin',
            'secretaria' => 'secretario',
            'dir_conquistadores' => 'director',
            'dir_aventureros' => 'director',
            'dir_guias_mayores' => 'director',
        ];

        foreach ($legacyMap as $fromName => $toName) {
            $from = Role::query()->where('name', $fromName)->first();
            $to = Role::query()->where('name', $toName)->first();
            if (! $from || ! $to || $from->id === $to->id) {
                continue;
            }

            $this->remapRoleForeignKeys($from->id, $to->id);

            if (Schema::hasTable('role_user')) {
                $userIds = DB::table('role_user')->where('role_id', $from->id)->pluck('user_id');
                foreach ($userIds as $userId) {
                    DB::table('role_user')->insertOrIgnore([
                        'user_id' => $userId,
                        'role_id' => $to->id,
                    ]);
                }
                DB::table('role_user')->where('role_id', $from->id)->delete();

                if ($toName === 'admin') {
                    DB::table('users')->whereIn('id', $userIds)->update(['is_admin' => true]);
                }
            }

            $from->permissions()->detach();
            $from->delete();
        }

        // Cualquier otro rol fuera del catálogo oficial deja de ser de sistema
        Role::query()
            ->whereNotIn('name', [
                'super_admin',
                'admin',
                'supervisor',
                'pastor',
                'juez',
                'director',
                'subdirector',
                'secretario',
                'tesorero',
                'invitado',
            ])
            ->update(['is_system' => false]);
    }

    private function remapRoleForeignKeys(int $fromRoleId, int $toRoleId): void
    {
        $tables = [
            'persona_organizacion_rol' => 'rol_id',
            'event_role' => 'role_id',
            'role_permission' => 'role_id',
        ];

        foreach ($tables as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            if ($table === 'role_permission') {
                // No fusionamos permisos aquí: se reasignan en sync posterior
                continue;
            }

            $rows = DB::table($table)->where($column, $fromRoleId)->get();
            foreach ($rows as $row) {
                $payload = (array) $row;
                $payload[$column] = $toRoleId;

                // Evitar choques de unique keys: si ya existe el destino, eliminar origen
                $existsQuery = DB::table($table)->where($column, $toRoleId);
                foreach ($payload as $key => $value) {
                    if ($key === 'id' || $key === $column) {
                        continue;
                    }
                    // Solo comparar columnas típicas de unicidad ligera
                    if (in_array($key, [
                        'persona_organizacion_id',
                        'usuario_id',
                        'organizacion_id',
                        'event_id',
                    ], true)) {
                        $existsQuery->where($key, $value);
                    }
                }

                if ($existsQuery->exists()) {
                    DB::table($table)->where('id', $row->id)->delete();
                } else {
                    DB::table($table)->where('id', $row->id)->update([$column => $toRoleId]);
                }
            }
        }
    }
}
