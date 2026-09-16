<?php

namespace Tests\Feature;

use App\Modules\Auth\Services\ClubesTenantAccess;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClubesHostMapTest extends TestCase
{
    public function test_root_id_prefers_origin_over_configured_and_header(): void
    {
        config([
            'clubes.root_organizacion_id' => 10,
            'clubes.hosts' => 'guias.clubric.online:10,aventureros.clubric.online:12',
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST', server: [
            'HTTP_X_CLUBES_CLIENT' => 'clubes',
            'HTTP_ORIGIN' => 'https://aventureros.clubric.online',
            'HTTP_X_CLUBES_ROOT_ID' => '10',
        ]);

        $this->assertSame(12, app(ClubesTenantAccess::class)->rootId($request));
    }

    public function test_root_id_falls_back_to_config_when_host_is_unknown(): void
    {
        config([
            'clubes.root_organizacion_id' => 10,
            'clubes.hosts' => 'guias.clubric.online:10',
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST', server: [
            'HTTP_X_CLUBES_CLIENT' => 'clubes',
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_X_CLUBES_ROOT_ID' => '99',
        ]);

        $this->assertSame(10, app(ClubesTenantAccess::class)->rootId($request));
    }

    public function test_root_id_uses_header_when_no_host_map_or_config(): void
    {
        config([
            'clubes.root_organizacion_id' => null,
            'clubes.hosts' => '',
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST', server: [
            'HTTP_X_CLUBES_CLIENT' => 'clubes',
            'HTTP_X_CLUBES_ROOT_ID' => '7',
        ]);

        $this->assertSame(7, app(ClubesTenantAccess::class)->rootId($request));
    }

    public function test_root_id_uses_host_header_when_origin_missing(): void
    {
        config([
            'clubes.root_organizacion_id' => 10,
            'clubes.hosts' => 'conquistadores.clubric.online:15',
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST', server: [
            'HTTP_X_CLUBES_CLIENT' => 'clubes',
            'HTTP_HOST' => 'conquistadores.clubric.online',
        ]);

        $this->assertSame(15, app(ClubesTenantAccess::class)->rootId($request));
    }

    public function test_root_id_uses_referer_when_origin_missing(): void
    {
        config([
            'clubes.root_organizacion_id' => 10,
            'clubes.hosts' => 'guias.clubric.online:10,aventureros.clubric.online:12',
        ]);

        $request = Request::create('/api/v1/auth/login', 'POST', server: [
            'HTTP_X_CLUBES_CLIENT' => 'clubes',
            'HTTP_REFERER' => 'https://aventureros.clubric.online/login',
            'HTTP_HOST' => 'api.clubric.online',
        ]);

        $this->assertSame(12, app(ClubesTenantAccess::class)->rootId($request));
    }
}
