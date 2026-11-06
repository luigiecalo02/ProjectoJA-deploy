<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\ClubesInformeParticipantesService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClubesInformeParticipantesController
{
    public function __construct(private readonly ClubesInformeParticipantesService $informes) {}

    public function publish(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        $origin = $this->resolveOrigin($request, $data['origin'] ?? null);

        return ApiResponse::success(
            $this->informes->publish(
                $request->user(),
                $data['desde'],
                $data['hasta'],
                $origin,
            ),
            'Informe publicado',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'origin' => ['nullable', 'string', 'max:255'],
        ]);
        $origin = $this->resolveOrigin($request, $data['origin'] ?? null);

        return ApiResponse::success($this->informes->listForOrg($request->user(), $origin));
    }

    public function destroy(Request $request, int $informe): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success(
            $this->informes->delete($request->user(), $informe),
            'Informe eliminado',
        );
    }

    public function setActive(Request $request, int $informe): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $result = $this->informes->setActive($request->user(), $informe, (bool) $data['activo']);

        return ApiResponse::success(
            $result,
            $result['activo'] ? 'Informe activado' : 'Informe desactivado',
        );
    }

    public function publicSummary(string $token): JsonResponse
    {
        return ApiResponse::success($this->informes->publicSummary($token));
    }

    public function publicPersona(string $token, int $persona): JsonResponse
    {
        return ApiResponse::success($this->informes->publicPersona($token, $persona));
    }

    private function resolveOrigin(Request $request, ?string $origin): string
    {
        $candidate = trim((string) ($origin ?: $request->headers->get('Origin') ?: ''));
        if ($candidate !== '' && preg_match('#^https?://#i', $candidate)) {
            return rtrim($candidate, '/');
        }

        return rtrim((string) config('app.frontend_url', config('app.url')), '/');
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'El informe solo se gestiona desde el front de Clubes.',
        );
    }
}
