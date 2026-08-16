<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $roleMap = [
            'director' => 'director',
            'subdirector' => 'subdirector',
            'secretaria' => 'secretario',
            'tesorero' => 'tesorero',
        ];

        $roleIds = DB::table('roles')
            ->whereIn('name', array_values($roleMap))
            ->pluck('id', 'name');

        // 1) Directores → persona_organizacion + persona_organizacion_rol
        if (Schema::hasTable('club_directors')) {
            $rows = DB::table('club_directors')
                ->join('clubes', 'clubes.id', '=', 'club_directors.club_id')
                ->join('users', 'users.id', '=', 'club_directors.user_id')
                ->whereNotNull('clubes.organizacion_id')
                ->whereNotNull('users.persona_id')
                ->whereNull('clubes.deleted_at')
                ->get([
                    'club_directors.ministry',
                    'clubes.organizacion_id',
                    'users.persona_id',
                ]);

            $now = now();
            foreach ($rows as $row) {
                $roleName = $roleMap[$row->ministry] ?? null;
                $rolId = $roleName ? ($roleIds[$roleName] ?? null) : null;
                if (! $rolId) {
                    continue;
                }

                $poId = DB::table('persona_organizacion')
                    ->where('persona_id', $row->persona_id)
                    ->where('organizacion_id', $row->organizacion_id)
                    ->value('id');

                if (! $poId) {
                    $poId = DB::table('persona_organizacion')->insertGetId([
                        'persona_id' => $row->persona_id,
                        'organizacion_id' => $row->organizacion_id,
                        'fecha_inicio' => $now->toDateString(),
                        'estado' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('persona_organizacion')->where('id', $poId)->update([
                        'estado' => true,
                        'fecha_fin' => null,
                        'updated_at' => $now,
                    ]);
                }

                $exists = DB::table('persona_organizacion_rol')
                    ->where('persona_organizacion_id', $poId)
                    ->where('rol_id', $rolId)
                    ->exists();

                if (! $exists) {
                    DB::table('persona_organizacion_rol')->insert([
                        'persona_organizacion_id' => $poId,
                        'rol_id' => $rolId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        // 2) Integrantes club_persona → persona_organizacion
        if (Schema::hasTable('club_persona')) {
            $members = DB::table('club_persona')
                ->join('clubes', 'clubes.id', '=', 'club_persona.club_id')
                ->whereNotNull('clubes.organizacion_id')
                ->whereNull('clubes.deleted_at')
                ->get([
                    'club_persona.persona_id',
                    'clubes.organizacion_id',
                ]);

            $now = now();
            foreach ($members as $row) {
                $exists = DB::table('persona_organizacion')
                    ->where('persona_id', $row->persona_id)
                    ->where('organizacion_id', $row->organizacion_id)
                    ->exists();

                if (! $exists) {
                    DB::table('persona_organizacion')->insert([
                        'persona_id' => $row->persona_id,
                        'organizacion_id' => $row->organizacion_id,
                        'fecha_inicio' => $now->toDateString(),
                        'estado' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('persona_organizacion')
                        ->where('persona_id', $row->persona_id)
                        ->where('organizacion_id', $row->organizacion_id)
                        ->update([
                            'estado' => true,
                            'fecha_fin' => null,
                            'updated_at' => $now,
                        ]);
                }
            }
        }

        Schema::dropIfExists('club_directors');
        Schema::dropIfExists('club_persona');
    }

    public function down(): void
    {
        Schema::create('club_persona', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubes')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->string('cargo')->nullable();
            $table->timestamps();
            $table->unique(['club_id', 'persona_id']);
        });

        Schema::create('club_directors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained('clubes')->cascadeOnDelete();
            $table->string('ministry', 40);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['club_id', 'ministry']);
        });
    }
};
