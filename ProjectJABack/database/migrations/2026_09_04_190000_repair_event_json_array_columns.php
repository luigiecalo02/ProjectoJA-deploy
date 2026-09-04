<?php

use App\Modules\Events\Models\Event;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $columns = ['categoria_ids', 'criterio_disponible_ids', 'tipos_evidencia', 'descuentos_directiva'];
        $rows = DB::table('events')->select(array_merge(['id'], $columns))->get();

        foreach ($rows as $row) {
            $updates = [];
            foreach ($columns as $column) {
                $raw = $row->{$column};
                if (! is_string($raw) || $raw === '') {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    continue;
                }
                $updates[$column] = Event::normalizeJsonArray($raw);
            }
            if ($updates !== []) {
                DB::table('events')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
