<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\CuentaBancaria;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class CuentaBancariaService
{
    public function __construct(private readonly ImageOptimizer $imageOptimizer) {}

    /** @return Collection<int, CuentaBancaria> */
    public function list(bool $soloActivas = false): Collection
    {
        return CuentaBancaria::query()
            ->with('qrFile')
            ->when($soloActivas, fn ($query) => $query->where('activo', true))
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): CuentaBancaria
    {
        $cuenta = CuentaBancaria::query()->create($this->normalized($data));

        return $cuenta->load('qrFile');
    }

    /** @param array<string, mixed> $data */
    public function update(CuentaBancaria $cuenta, array $data): CuentaBancaria
    {
        $cuenta->update($this->normalized($data, $cuenta));

        return $cuenta->fresh('qrFile');
    }

    public function delete(CuentaBancaria $cuenta): void
    {
        if (DB::table('events')->where('cuenta_bancaria_id', $cuenta->id)->exists()) {
            throw ValidationException::withMessages([
                'cuenta' => ['No se puede eliminar: hay eventos que usan esta cuenta.'],
            ]);
        }
        $this->deleteQrFile($cuenta);
        $cuenta->delete();
    }

    public function storeQr(CuentaBancaria $cuenta, UploadedFile $file, User $actor): CuentaBancaria
    {
        $stored = $this->imageOptimizer->store($file, 'cuentas-bancarias/'.$cuenta->id, 'qr');
        $this->deleteQrFile($cuenta);
        $record = StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);
        $cuenta->update(['qr_file_id' => $record->id]);

        return $cuenta->fresh('qrFile');
    }

    public function deleteQr(CuentaBancaria $cuenta): CuentaBancaria
    {
        $this->deleteQrFile($cuenta);
        $cuenta->update(['qr_file_id' => null]);

        return $cuenta->fresh('qrFile');
    }

    /** @return array<string, mixed> */
    public function toPayload(?CuentaBancaria $cuenta): ?array
    {
        if (! $cuenta) {
            return null;
        }
        $cuenta->loadMissing('qrFile');

        return [
            'id' => $cuenta->id,
            'nombre' => $cuenta->nombre,
            'banco' => $cuenta->banco,
            'tipo_cuenta' => $cuenta->tipo_cuenta,
            'numero_cuenta' => $cuenta->numero_cuenta,
            'titular' => $cuenta->titular,
            'identificacion_titular' => $cuenta->identificacion_titular,
            'activo' => (bool) $cuenta->activo,
            'orden' => (int) $cuenta->orden,
            'qr_url' => $cuenta->qrFile?->path
                ? Storage::disk('public')->url($cuenta->qrFile->path)
                : null,
        ];
    }

    /** @param array<string, mixed> $data */
    private function normalized(array $data, ?CuentaBancaria $cuenta = null): array
    {
        return [
            'nombre' => trim((string) $data['nombre']),
            'banco' => isset($data['banco']) ? trim((string) $data['banco']) ?: null : $cuenta?->banco,
            'tipo_cuenta' => (string) $data['tipo_cuenta'],
            'numero_cuenta' => trim((string) $data['numero_cuenta']),
            'titular' => isset($data['titular']) ? trim((string) $data['titular']) ?: null : $cuenta?->titular,
            'identificacion_titular' => isset($data['identificacion_titular'])
                ? trim((string) $data['identificacion_titular']) ?: null
                : $cuenta?->identificacion_titular,
            'activo' => array_key_exists('activo', $data) ? (bool) $data['activo'] : ($cuenta?->activo ?? true),
            'orden' => isset($data['orden']) ? (int) $data['orden'] : ($cuenta?->orden ?? 0),
        ];
    }

    private function deleteQrFile(CuentaBancaria $cuenta): void
    {
        $file = $cuenta->qrFile;
        if (! $file) {
            return;
        }
        if ($file->path) {
            Storage::disk('public')->delete($file->path);
        }
        $file->delete();
    }
}
