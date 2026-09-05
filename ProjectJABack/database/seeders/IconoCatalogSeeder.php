<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IconoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $orden = 0;

        foreach ($this->catalog() as $item) {
            $orden++;
            DB::table('iconos')->updateOrInsert(
                ['slug' => $item['slug']],
                [
                    'nombre' => $item['nombre'],
                    'categoria' => $item['categoria'],
                    'etiquetas' => json_encode($item['etiquetas'], JSON_UNESCAPED_UNICODE),
                    'tipo' => 'prime',
                    'valor' => $item['valor'],
                    'orden' => $item['orden'] ?? $orden,
                    'estado' => true,
                    'es_sistema' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $this->importUsedIcons($now, $orden);
    }

    /**
     * @return list<array{nombre: string, slug: string, categoria: string, etiquetas: list<string>, valor: string, orden?: int}>
     */
    private function catalog(): array
    {
        $groups = [
            'eventos' => [
                ['Calendario', 'pi pi-calendar', ['evento', 'fecha', 'agenda']],
                ['Calendario plus', 'pi pi-calendar-plus', ['evento', 'nuevo', 'agregar']],
                ['Calendario reloj', 'pi pi-calendar-clock', ['evento', 'hora', 'cita']],
                ['Bandera', 'pi pi-flag', ['evento', 'camporee', 'meta']],
                ['Estrella', 'pi pi-star', ['especialidad', 'favorito', 'logro']],
                ['Estrella llena', 'pi pi-star-fill', ['especialidad', 'premio']],
                ['Trofeo', 'pi pi-trophy', ['premio', 'ganador', 'competencia']],
                ['Ticket', 'pi pi-ticket', ['entrada', 'inscripcion']],
                ['Megáfono', 'pi pi-megaphone', ['anuncio', 'convocatoria']],
                ['Campana', 'pi pi-bell', ['aviso', 'notificacion']],
                ['Rayo', 'pi pi-bolt', ['desafio', 'energia', 'rapido']],
                ['Diana', 'pi pi-bullseye', ['meta', 'objetivo']],
                ['Corazón', 'pi pi-heart', ['servicio', 'cuidado']],
                ['Corazón lleno', 'pi pi-heart-fill', ['amor', 'servicio']],
                ['Sol', 'pi pi-sun', ['aventureros', 'dia']],
                ['Luna', 'pi pi-moon', ['noche', 'vigilia']],
                ['Fuego', 'pi pi-sparkles', ['campamento', 'fogata']],
            ],
            'clubes' => [
                ['Usuarios', 'pi pi-users', ['club', 'grupo', 'equipo']],
                ['Usuario', 'pi pi-user', ['persona', 'miembro']],
                ['Usuario plus', 'pi pi-user-plus', ['inscribir', 'nuevo']],
                ['Edificio', 'pi pi-building', ['iglesia', 'sede']],
                ['Columnas', 'pi pi-building-columns', ['institucion']],
                ['Sitemap', 'pi pi-sitemap', ['organizacion', 'estructura']],
                ['Id card', 'pi pi-id-card', ['carnet', 'identidad']],
                ['Escudo', 'pi pi-shield', ['proteccion', 'seguro']],
                ['Insignia', 'pi pi-verified', ['honor', 'confianza']],
                ['Corona', 'pi pi-crown', ['lider', 'guia']],
                ['Hashtag', 'pi pi-hashtag', ['unidad', 'codigo']],
            ],
            'deportes' => [
                ['Actividad', 'pi pi-bolt', ['deporte', 'competencia']],
                ['Cronómetro', 'pi pi-stopwatch', ['tiempo', 'carrera']],
                ['Dirección', 'pi pi-directions', ['recorrido', 'ruta']],
                ['Auto', 'pi pi-car', ['traslado', 'viaje']],
                ['Caminar', 'pi pi-arrow-up-right', ['marcha', 'senderismo']],
                ['Mano', 'pi pi-thumbs-up', ['animo', 'ok']],
                ['Descanso', 'pi pi-pause', ['pausa']],
                ['Play', 'pi pi-play', ['inicio', 'arrancar']],
                ['Replay', 'pi pi-replay', ['repetir']],
            ],
            'naturaleza' => [
                ['Sol naturaleza', 'pi pi-sun', ['aire libre', 'dia']],
                ['Nube', 'pi pi-cloud', ['clima']],
                ['Globo', 'pi pi-globe', ['mundo', 'mision']],
                ['Brújula', 'pi pi-compass', ['orientacion', 'exploracion']],
                ['Mapa', 'pi pi-map', ['camporee', 'terreno']],
                ['Marcador', 'pi pi-map-marker', ['lugar', 'punto']],
                ['Home', 'pi pi-home', ['campamento', 'base']],
                ['Agua', 'pi pi-wave-pulse', ['rio', 'laguna']],
            ],
            'personas' => [
                ['Persona', 'pi pi-user', ['participante']],
                ['Grupo', 'pi pi-users', ['equipo', 'unidad']],
                ['Agregar persona', 'pi pi-user-plus', ['alta']],
                ['Quitar persona', 'pi pi-user-minus', ['baja']],
                ['Editar persona', 'pi pi-user-edit', ['ficha']],
                ['Juez', 'pi pi-eye', ['evaluacion', 'juez']],
                ['Comentario', 'pi pi-comments', ['observacion']],
                ['Manos', 'pi pi-thumbs-up', ['acuerdo', 'equipo']],
            ],
            'tiempo' => [
                ['Reloj', 'pi pi-clock', ['hora', 'puntualidad']],
                ['Historial', 'pi pi-history', ['pasado']],
                ['Hora', 'pi pi-hourglass', ['espera', 'limite']],
                ['Calendario minus', 'pi pi-calendar-minus', ['cancelar fecha']],
                ['Calendario times', 'pi pi-calendar-times', ['cierre']],
                ['Spinner', 'pi pi-spinner', ['proceso']],
            ],
            'comunicacion' => [
                ['Sobre', 'pi pi-envelope', ['correo', 'mensaje']],
                ['Enviar', 'pi pi-send', ['envio']],
                ['Teléfono', 'pi pi-phone', ['llamada']],
                ['WhatsApp', 'pi pi-whatsapp', ['mensaje']],
                ['Comentarios', 'pi pi-comment', ['chat']],
                ['Video', 'pi pi-video', ['youtube', 'grabacion']],
                ['Cámara', 'pi pi-camera', ['foto', 'evidencia']],
                ['Micrófono', 'pi pi-microphone', ['audio']],
                ['Volumen', 'pi pi-volume-up', ['sonido']],
                ['Link', 'pi pi-link', ['url']],
            ],
            'archivos' => [
                ['Libro', 'pi pi-book', ['biblia', 'estudio']],
                ['Archivo', 'pi pi-file', ['documento']],
                ['PDF', 'pi pi-file-pdf', ['pdf']],
                ['Imagen', 'pi pi-image', ['foto']],
                ['Imágenes', 'pi pi-images', ['galeria']],
                ['Carpeta', 'pi pi-folder', ['materiales']],
                ['Carpeta abierta', 'pi pi-folder-open', ['recursos']],
                ['Clip', 'pi pi-paperclip', ['adjunto']],
                ['Descargar', 'pi pi-download', ['bajar']],
                ['Subir', 'pi pi-upload', ['cargar']],
                ['Lista', 'pi pi-list', ['criterio', 'tareas']],
                ['Checklist', 'pi pi-list-check', ['evaluacion', 'criterio']],
                ['Check', 'pi pi-check', ['listo', 'ok']],
                ['Check círculo', 'pi pi-check-circle', ['aprobado']],
                ['Lápiz', 'pi pi-pencil', ['editar']],
                ['Etiqueta', 'pi pi-tag', ['categoria']],
                ['Etiquetas', 'pi pi-tags', ['catalogo']],
                ['Bookmark', 'pi pi-bookmark', ['marcar']],
                ['Caja', 'pi pi-box', ['recurso']],
                ['Wrench', 'pi pi-wrench', ['habilidad', 'taller']],
            ],
            'orientacion' => [
                ['Mapa ruta', 'pi pi-map', ['ruta']],
                ['Punto', 'pi pi-map-marker', ['ubicacion']],
                ['Brújula ruta', 'pi pi-compass', ['norte']],
                ['Casa', 'pi pi-home', ['base']],
                ['Globo tierra', 'pi pi-globe', ['mision']],
                ['Buscar', 'pi pi-search', ['encontrar']],
                ['Filtro', 'pi pi-filter', ['filtrar']],
                ['Info', 'pi pi-info-circle', ['ayuda']],
                ['Pregunta', 'pi pi-question-circle', ['duda']],
                ['Exclamación', 'pi pi-exclamation-triangle', ['alerta']],
            ],
            'sistema' => [
                ['Engrane', 'pi pi-cog', ['configuracion']],
                ['Ajustes', 'pi pi-sliders-h', ['opciones']],
                ['Candado', 'pi pi-lock', ['privado']],
                ['Candado abierto', 'pi pi-lock-open', ['publico']],
                ['Ojo', 'pi pi-eye', ['visible']],
                ['Ojo tapado', 'pi pi-eye-slash', ['oculto']],
                ['Basura', 'pi pi-trash', ['eliminar']],
                ['Copiar', 'pi pi-copy', ['duplicar']],
                ['Más', 'pi pi-plus', ['agregar']],
                ['Menos', 'pi pi-minus', ['quitar']],
                ['Times', 'pi pi-times', ['cerrar']],
                ['Ban', 'pi pi-ban', ['no']],
                ['Key', 'pi pi-key', ['acceso']],
                ['Wifi', 'pi pi-wifi', ['online']],
                ['Sync', 'pi pi-sync', ['actualizar']],
                ['Chart', 'pi pi-chart-bar', ['puntos', 'estadistica']],
                ['Chart linea', 'pi pi-chart-line', ['progreso']],
                ['Tabla', 'pi pi-table', ['listado']],
                ['Th large', 'pi pi-th-large', ['mosaico']],
                ['Bars', 'pi pi-bars', ['menu']],
            ],
        ];

        $items = [];
        $seen = [];
        foreach ($groups as $categoria => $rows) {
            $i = 0;
            foreach ($rows as [$nombre, $valor, $etiquetas]) {
                if (isset($seen[$valor])) {
                    continue;
                }
                $seen[$valor] = true;
                $i++;
                $items[] = [
                    'nombre' => $nombre,
                    'slug' => Str::slug($categoria.'-'.$nombre),
                    'categoria' => $categoria,
                    'etiquetas' => array_values(array_unique([...$etiquetas, $categoria, Str::slug($nombre, ' ')])),
                    'valor' => $valor,
                    'orden' => $i,
                ];
            }
        }

        return $items;
    }

    private function importUsedIcons(\DateTimeInterface $now, int $orden): void
    {
        $used = [];
        $tables = [
            ['tipo_evento', 'eventos'],
            ['categoria_subevento', 'eventos'],
            ['criterio_evaluacion', 'archivos'],
            ['events', 'personalizado'],
        ];

        foreach ($tables as [$table, $categoria]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'icono')) {
                continue;
            }
            $rows = DB::table($table)->whereNotNull('icono')->pluck('icono');
            foreach ($rows as $icono) {
                $valor = trim((string) $icono);
                if ($valor === '' || ! str_starts_with($valor, 'pi ')) {
                    continue;
                }
                $used[$valor] = $categoria;
            }
        }

        foreach ($used as $valor => $categoria) {
            $exists = DB::table('iconos')->where('valor', $valor)->exists();
            if ($exists) {
                continue;
            }
            $orden++;
            $name = Str::of($valor)->afterLast('pi-')->replace('-', ' ')->title()->toString();
            DB::table('iconos')->insert([
                'nombre' => $name !== '' ? $name : $valor,
                'slug' => Str::slug('usado-'.$valor),
                'categoria' => $categoria,
                'etiquetas' => json_encode(['usado', $categoria], JSON_UNESCAPED_UNICODE),
                'tipo' => 'prime',
                'valor' => $valor,
                'orden' => $orden,
                'estado' => true,
                'es_sistema' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
