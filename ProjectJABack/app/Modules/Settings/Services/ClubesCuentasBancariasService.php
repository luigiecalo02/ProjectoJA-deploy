<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\CuentaBancaria;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\ImageOptimizer;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class ClubesCuentasBancariasService
{
    public function __construct(
        private readonly ImageOptimizer $images,
        private readonly PublicFileService $publicFiles,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor): array
    {
        $orgId = $this->assertCanView($actor);

        return CuentaBancaria::query()
            ->with('qrFile')
            ->where('organizacion_id', $orgId)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->map(fn (CuentaBancaria $cuenta) => $this->payload($cuenta))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data): array
    {
        $orgId = $this->assertCanWrite($actor);
        $cuenta = CuentaBancaria::query()->create([
            ...$this->normalized($data),
            'organizacion_id' => $orgId,
        ]);

        return $this->payload($cuenta->load('qrFile'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(User $actor, int $id, array $data): array
    {
        $orgId = $this->assertCanWrite($actor);
        $cuenta = $this->owned($id, $orgId);
        $cuenta->update($this->normalized($data, $cuenta));

        return $this->payload($cuenta->fresh('qrFile') ?? $cuenta);
    }

    public function delete(User $actor, int $id): void
    {
        $orgId = $this->assertCanWrite($actor);
        $cuenta = $this->owned($id, $orgId);
        $this->deleteQrFile($cuenta);
        $cuenta->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function storeQr(User $actor, int $id, UploadedFile $file): array
    {
        $orgId = $this->assertCanWrite($actor);
        $cuenta = $this->owned($id, $orgId);
        $stored = $this->images->store($file, 'cuentas-bancarias/'.$cuenta->id, 'qr');
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

        return $this->payload($cuenta->fresh('qrFile') ?? $cuenta);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteQr(User $actor, int $id): array
    {
        $orgId = $this->assertCanWrite($actor);
        $cuenta = $this->owned($id, $orgId);
        $this->deleteQrFile($cuenta);
        $cuenta->update(['qr_file_id' => null]);

        return $this->payload($cuenta->fresh('qrFile') ?? $cuenta);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(CuentaBancaria $cuenta): array
    {
        return $this->payload($cuenta);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toPayloadOrNull(?CuentaBancaria $cuenta): ?array
    {
        return $cuenta ? $this->payload($cuenta) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forAbono(?CuentaBancaria $cuenta, int $orgId): ?array
    {
        if ($cuenta) {
            return $this->payload($cuenta);
        }

        $fallback = CuentaBancaria::query()
            ->with('qrFile')
            ->where('organizacion_id', $orgId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('id')
            ->first();

        return $fallback ? $this->payload($fallback) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CuentaBancaria $cuenta): array
    {
        $cuenta->loadMissing('qrFile');

        return [
            'id' => (int) $cuenta->id,
            'organizacion_id' => $cuenta->organizacion_id !== null ? (int) $cuenta->organizacion_id : null,
            'nombre' => $cuenta->nombre,
            'banco' => $cuenta->banco,
            'tipo_cuenta' => $cuenta->tipo_cuenta,
            'numero_cuenta' => $cuenta->numero_cuenta,
            'titular' => $cuenta->titular,
            'identificacion_titular' => $cuenta->identificacion_titular,
            'activo' => (bool) $cuenta->activo,
            'orden' => (int) $cuenta->orden,
            'qr_url' => $this->publicFiles->url($cuenta->qrFile?->path),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalized(array $data, ?CuentaBancaria $cuenta = null): array
    {
        $titular = trim((string) ($data['titular'] ?? ''));
        $banco = trim((string) ($data['banco'] ?? ''));
        $nombre = trim((string) ($data['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = $banco !== '' ? $banco : ($titular !== '' ? $titular : 'Cuenta bancaria');
        }

        return [
            'nombre' => $nombre,
            'banco' => $banco !== '' ? $banco : ($cuenta?->banco),
            'tipo_cuenta' => (string) $data['tipo_cuenta'],
            'numero_cuenta' => trim((string) $data['numero_cuenta']),
            'titular' => $titular !== '' ? $titular : null,
            'identificacion_titular' => isset($data['identificacion_titular'])
                ? (trim((string) $data['identificacion_titular']) ?: null)
                : $cuenta?->identificacion_titular,
            'activo' => array_key_exists('activo', $data) ? (bool) $data['activo'] : ($cuenta?->activo ?? true),
            'orden' => isset($data['orden']) ? (int) $data['orden'] : ($cuenta?->orden ?? 0),
        ];
    }

    private function deleteQrFile(CuentaBancaria $cuenta): void
    {
        $cuenta->loadMissing('qrFile');
        $file = $cuenta->qrFile;
        if (! $file) {
            return;
        }
        if ($file->path) {
            Storage::disk('public')->delete($file->path);
        }
        $file->delete();
    }

    private function owned(int $id, int $orgId): CuentaBancaria
    {
        $cuenta = CuentaBancaria::query()
            ->where('organizacion_id', $orgId)
            ->where('id', $id)
            ->first();
        abort_unless($cuenta, Response::HTTP_NOT_FOUND);

        return $cuenta;
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $actor->isSuperAdmin()
            || $actor->hasPermission('settings.view')
            || $actor->hasPermission('finanzas.view')
            || $actor->hasPermission('abonos.view')
            || in_array('director', $actor->roleNames(), true)
            || in_array('subdirector', $actor->roleNames(), true)
            || in_array('tesorero', $actor->roleNames(), true)
            || in_array('secretario', $actor->roleNames(), true),
            Response::HTTP_FORBIDDEN,
            'No puedes ver las cuentas bancarias.',
        );

        return $orgId;
    }

    private function assertCanWrite(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $actor->isSuperAdmin()
            || $actor->hasPermission('settings.update')
            || in_array('director', $actor->roleNames(), true),
            Response::HTTP_FORBIDDEN,
            'No puedes gestionar las cuentas bancarias.',
        );

        return $orgId;
    }
}
