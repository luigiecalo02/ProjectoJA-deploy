<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Icono;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IconoCatalogService
{
    public const CATEGORIAS = [
        'eventos',
        'clubes',
        'deportes',
        'naturaleza',
        'personas',
        'tiempo',
        'comunicacion',
        'archivos',
        'orientacion',
        'sistema',
        'personalizado',
    ];

    /**
     * @return list<Icono>
     */
    public function list(bool $incluirInactivos = false, ?string $categoria = null, ?string $q = null): array
    {
        $query = Icono::query()->orderBy('categoria')->orderBy('orden')->orderBy('nombre');

        if (! $incluirInactivos) {
            $query->where('estado', true);
        }

        if ($categoria) {
            $query->where('categoria', $categoria);
        }

        if ($q !== null && trim($q) !== '') {
            $term = '%'.mb_strtolower(trim($q)).'%';
            $query->where(function ($inner) use ($term) {
                $inner->whereRaw('LOWER(nombre) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(slug) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(categoria) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(valor) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(CAST(etiquetas AS CHAR)) LIKE ?', [$term]);
            });
        }

        return $query->get()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?UploadedFile $archivo = null): Icono
    {
        [$tipo, $valor] = $this->resolveTipoValor($data, $archivo, null);

        return Icono::query()->create([
            'nombre' => trim((string) $data['nombre']),
            'slug' => $this->resolveSlug((string) ($data['slug'] ?? ''), (string) $data['nombre']),
            'categoria' => $this->safeCategoria($data['categoria'] ?? 'personalizado'),
            'etiquetas' => $this->normalizeEtiquetas($data['etiquetas'] ?? []),
            'tipo' => $tipo,
            'valor' => $valor,
            'orden' => (int) ($data['orden'] ?? 0),
            'estado' => array_key_exists('estado', $data) ? (bool) $data['estado'] : true,
            'es_sistema' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Icono $icono, array $data, ?UploadedFile $archivo = null): Icono
    {
        if (isset($data['nombre'])) {
            $icono->nombre = trim((string) $data['nombre']);
        }

        if (array_key_exists('slug', $data) || isset($data['nombre'])) {
            $source = array_key_exists('slug', $data) && filled($data['slug'])
                ? (string) $data['slug']
                : (string) $icono->nombre;
            $icono->slug = $this->resolveSlug($source, (string) $icono->nombre, $icono->id);
        }

        if (array_key_exists('categoria', $data)) {
            $icono->categoria = $this->safeCategoria($data['categoria']);
        }

        if (array_key_exists('etiquetas', $data)) {
            $icono->etiquetas = $this->normalizeEtiquetas($data['etiquetas']);
        }

        if (array_key_exists('orden', $data)) {
            $icono->orden = (int) $data['orden'];
        }

        if (array_key_exists('estado', $data)) {
            $icono->estado = (bool) $data['estado'];
        }

        if ($archivo || array_key_exists('valor', $data) || array_key_exists('tipo', $data)) {
            [$tipo, $valor] = $this->resolveTipoValor($data, $archivo, $icono);
            if ($icono->tipo === 'imagen' && $valor !== $icono->valor) {
                $this->deleteStoredFile($icono->valor);
            }
            $icono->tipo = $tipo;
            $icono->valor = $valor;
        }

        $icono->save();

        return $icono->refresh();
    }

    public function delete(Icono $icono): void
    {
        if ($icono->es_sistema) {
            throw ValidationException::withMessages([
                'icono' => ['No se puede eliminar un icono creado por el sistema.'],
            ]);
        }

        if ($icono->tipo === 'imagen') {
            $this->deleteStoredFile($icono->valor);
        }

        $icono->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}
     */
    private function resolveTipoValor(array $data, ?UploadedFile $archivo, ?Icono $current): array
    {
        if ($archivo) {
            $ext = strtolower($archivo->getClientOriginalExtension() ?: $archivo->extension() ?: 'png');
            $base = Str::slug((string) ($data['nombre'] ?? $current?->nombre ?? 'icono'));
            if ($base === '') {
                $base = 'icono';
            }
            $filename = $base.'-'.Str::lower(Str::random(6)).'.'.$ext;
            $path = $archivo->storeAs('iconos', $filename, 'public');

            return ['imagen', $path];
        }

        $valor = trim((string) ($data['valor'] ?? $current?->valor ?? ''));
        if ($valor === '') {
            throw ValidationException::withMessages([
                'valor' => ['Elige un icono o sube una imagen / GIF.'],
            ]);
        }

        $tipo = (string) ($data['tipo'] ?? $current?->tipo ?? 'prime');
        if (! in_array($tipo, ['prime', 'imagen'], true)) {
            $tipo = str_starts_with($valor, 'pi ') ? 'prime' : 'imagen';
        }

        return [$tipo, $valor];
    }

    /**
     * @param  mixed  $raw
     * @return list<string>
     */
    private function normalizeEtiquetas(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : preg_split('/[,;]+/', $raw);
        }
        $items = is_array($raw) ? $raw : [];
        $clean = [];
        foreach ($items as $item) {
            $tag = trim(mb_strtolower((string) $item));
            if ($tag !== '') {
                $clean[$tag] = $tag;
            }
        }

        return array_values($clean);
    }

    private function safeCategoria(mixed $value): string
    {
        $categoria = trim((string) $value);

        return in_array($categoria, self::CATEGORIAS, true) ? $categoria : 'personalizado';
    }

    private function resolveSlug(string $slug, string $nombre, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug !== '' ? $slug : $nombre);
        if ($base === '') {
            $base = 'icono';
        }

        $candidate = $base;
        $suffix = 2;
        while (
            Icono::query()
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function deleteStoredFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'pi ') || str_contains($path, '://')) {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
