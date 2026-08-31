<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lugares')) {
            Schema::create('lugares', function (Blueprint $table) {
                $table->id();
                $table->string('nombre');
                $table->text('descripcion')->nullable();
                $table->decimal('latitud', 10, 7)->nullable();
                $table->decimal('longitud', 10, 7)->nullable();
                $table->unsignedTinyInteger('nivel_zoom')->default(16);
                $table->string('estado', 20)->default('activo');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('terrenos') && ! Schema::hasColumn('terrenos', 'lugar_id')) {
            Schema::table('terrenos', function (Blueprint $table) {
                $table->unsignedBigInteger('lugar_id')->nullable()->after('id');
                $table->foreign('lugar_id')->references('id')->on('lugares')->nullOnDelete();
            });
        }

        if (Schema::hasTable('cabanas') && ! Schema::hasColumn('cabanas', 'lugar_id')) {
            Schema::table('cabanas', function (Blueprint $table) {
                $table->unsignedBigInteger('lugar_id')->nullable()->after('id');
                $table->foreign('lugar_id')->references('id')->on('lugares')->nullOnDelete();
            });
        }

        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (! Schema::hasColumn('events', 'lugar_id')) {
                    $table->unsignedBigInteger('lugar_id')->nullable()->after('lugar');
                    $table->foreign('lugar_id')->references('id')->on('lugares')->nullOnDelete();
                }
                if (! Schema::hasColumn('events', 'usar_lotes')) {
                    $table->boolean('usar_lotes')->default(false)->after('lugar_id');
                }
                if (! Schema::hasColumn('events', 'usar_cabanas')) {
                    $table->boolean('usar_cabanas')->default(false)->after('usar_lotes');
                }
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (Schema::hasColumn('events', 'lugar_id')) {
                    $table->dropForeign(['lugar_id']);
                    $table->dropColumn('lugar_id');
                }
                foreach (['usar_lotes', 'usar_cabanas'] as $column) {
                    if (Schema::hasColumn('events', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('cabanas') && Schema::hasColumn('cabanas', 'lugar_id')) {
            Schema::table('cabanas', function (Blueprint $table) {
                $table->dropForeign(['lugar_id']);
                $table->dropColumn('lugar_id');
            });
        }

        if (Schema::hasTable('terrenos') && Schema::hasColumn('terrenos', 'lugar_id')) {
            Schema::table('terrenos', function (Blueprint $table) {
                $table->dropForeign(['lugar_id']);
                $table->dropColumn('lugar_id');
            });
        }

        Schema::dropIfExists('lugares');
    }

    private function backfill(): void
    {
        $now = now();

        if (Schema::hasTable('terrenos') && Schema::hasColumn('terrenos', 'lugar_id')) {
            foreach (DB::table('terrenos')->whereNull('lugar_id')->orderBy('id')->get() as $terreno) {
                $lugarId = DB::table('lugares')->insertGetId([
                    'nombre' => $terreno->nombre ?: 'Terreno '.$terreno->id,
                    'descripcion' => $terreno->descripcion,
                    'latitud' => $terreno->latitud,
                    'longitud' => $terreno->longitud,
                    'nivel_zoom' => $terreno->nivel_zoom ?: 16,
                    'estado' => $terreno->estado === 'inactivo' ? 'inactivo' : 'activo',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('terrenos')->where('id', $terreno->id)->update(['lugar_id' => $lugarId]);
            }
        }

        if (Schema::hasTable('cabanas') && Schema::hasColumn('cabanas', 'lugar_id')) {
            $orphans = DB::table('cabanas')->whereNull('lugar_id')->count();
            if ($orphans > 0) {
                $orphanId = DB::table('lugares')->where('nombre', 'Sin ubicación')->value('id')
                    ?? DB::table('lugares')->insertGetId([
                        'nombre' => 'Sin ubicación',
                        'descripcion' => 'Cabañas sin coordenadas. Reasigne el lugar desde el catálogo.',
                        'estado' => 'activo',
                        'nivel_zoom' => 12,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                DB::table('cabanas')->whereNull('lugar_id')->update(['lugar_id' => $orphanId]);
            }
        }

        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'lugar_id')) {
            return;
        }

        if (Schema::hasTable('eventos_terrenos')) {
            $rows = DB::table('eventos_terrenos')
                ->join('terrenos', 'terrenos.id', '=', 'eventos_terrenos.terreno_id')
                ->whereNotNull('terrenos.lugar_id')
                ->select('eventos_terrenos.evento_id', 'terrenos.lugar_id')
                ->get();

            foreach ($rows as $row) {
                $lugar = DB::table('lugares')->where('id', $row->lugar_id)->first();
                $payload = [
                    'lugar_id' => $row->lugar_id,
                    'usar_lotes' => true,
                ];
                if ($lugar && Schema::hasColumn('events', 'lugar')) {
                    $event = DB::table('events')->where('id', $row->evento_id)->first();
                    if ($event && empty($event->lugar)) {
                        $payload['lugar'] = $lugar->nombre;
                    }
                    if ($event && $event->latitud === null && $lugar->latitud !== null) {
                        $payload['latitud'] = $lugar->latitud;
                        $payload['longitud'] = $lugar->longitud;
                    }
                }
                DB::table('events')->where('id', $row->evento_id)->update($payload);
            }
        }

        if (! Schema::hasTable('evento_cabanas')) {
            return;
        }

        $rows = DB::table('evento_cabanas')
            ->join('cabanas', 'cabanas.id', '=', 'evento_cabanas.cabana_id')
            ->whereNotNull('cabanas.lugar_id')
            ->select('evento_cabanas.evento_id', 'cabanas.lugar_id')
            ->orderBy('evento_cabanas.id')
            ->get();

        foreach ($rows as $row) {
            $event = DB::table('events')->where('id', $row->evento_id)->first();
            if (! $event) {
                continue;
            }
            $payload = ['usar_cabanas' => true];
            if (empty($event->lugar_id)) {
                $lugar = DB::table('lugares')->where('id', $row->lugar_id)->first();
                $payload['lugar_id'] = $row->lugar_id;
                if ($lugar && empty($event->lugar)) {
                    $payload['lugar'] = $lugar->nombre;
                }
            }
            DB::table('events')->where('id', $row->evento_id)->update($payload);
        }
    }
};
