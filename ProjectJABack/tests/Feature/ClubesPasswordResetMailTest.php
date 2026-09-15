<?php

namespace Tests\Feature;

use App\Modules\Auth\Services\BrandedMailView;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Settings\Models\AppSetting;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClubesPasswordResetMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->ensureOrgTipos();
    }

    public function test_clubes_reset_mail_uses_configured_logo_and_banner(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/club-logo.png', 'logo');
        Storage::disk('public')->put('brand/club-banner.jpg', 'banner');

        $parent = $this->createOrg('Asociación Caribe', Organizacion::TIPO_ASOCIACION);
        $settings = AppSetting::forOrganizacion($parent->id);
        $settings->clubes = AppSetting::normalizeClubesConfig([
            ...AppSetting::defaultClubesConfig(),
            'kicker' => 'Club de prueba',
            'title' => 'HALCONES',
            'logo_path' => 'brand/club-logo.png',
            'banner_path' => 'brand/club-banner.jpg',
        ]);
        $settings->save();

        $this->actingAsClubes($parent->id);

        $layout = app(BrandedMailView::class)->layout($parent->id);

        $this->assertSame('Club de prueba', $layout['line1']);
        $this->assertSame('HALCONES', $layout['line2']);
        $this->assertSame('HALCONES', $layout['brandName']);
        $this->assertSame(Storage::disk('public')->path('brand/club-logo.png'), $layout['logoPath']);
        $this->assertSame(Storage::disk('public')->path('brand/club-banner.jpg'), $layout['heroPath']);
        $this->assertNull($layout['patternPath']);
    }

    public function test_other_app_reset_mail_keeps_platform_branding(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/club-logo.png', 'logo');
        Storage::disk('public')->put('brand/club-banner.jpg', 'banner');

        $parent = $this->createOrg('Asociación Caribe', Organizacion::TIPO_ASOCIACION);
        $settings = AppSetting::forOrganizacion($parent->id);
        $settings->clubes = AppSetting::normalizeClubesConfig([
            ...AppSetting::defaultClubesConfig(),
            'title' => 'HALCONES',
            'logo_path' => 'brand/club-logo.png',
            'banner_path' => 'brand/club-banner.jpg',
        ]);
        $settings->save();

        $layout = app(BrandedMailView::class)->layout($parent->id);

        $this->assertSame('ProjectJA', $layout['brandName']);
        $this->assertNotSame(Storage::disk('public')->path('brand/club-logo.png'), $layout['logoPath']);
        $this->assertNotSame(Storage::disk('public')->path('brand/club-banner.jpg'), $layout['heroPath']);
    }

    public function test_child_without_images_uses_parent_clubes_assets(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/parent-logo.png', 'logo');
        Storage::disk('public')->put('brand/parent-banner.jpg', 'banner');

        $parent = $this->createOrg('Asociación Caribe', Organizacion::TIPO_ASOCIACION);
        $child = $this->createOrg('Iglesia Central', Organizacion::TIPO_IGLESIA, $parent->id);

        $settings = AppSetting::forOrganizacion($parent->id);
        $settings->clubes = AppSetting::normalizeClubesConfig([
            ...AppSetting::defaultClubesConfig(),
            'title' => 'CARIBE',
            'logo_path' => 'brand/parent-logo.png',
            'banner_path' => 'brand/parent-banner.jpg',
        ]);
        $settings->save();

        $this->actingAsClubes($parent->id);

        $layout = app(BrandedMailView::class)->layout($child->id);

        $this->assertSame(Storage::disk('public')->path('brand/parent-logo.png'), $layout['logoPath']);
        $this->assertSame(Storage::disk('public')->path('brand/parent-banner.jpg'), $layout['heroPath']);
    }

    private function actingAsClubes(int $rootId): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('X-Clubes-Client', 'clubes');
        $request->headers->set('X-Clubes-Root-Id', (string) $rootId);
        $this->app->instance('request', $request);
    }

    private function createOrg(string $nombre, int $tipoId, ?int $padreId = null): Organizacion
    {
        return Organizacion::query()->create([
            'tipo_organizacion_id' => $tipoId,
            'organizacion_padre_id' => $padreId,
            'nombre' => $nombre,
            'codigo' => strtoupper(substr(md5($nombre.microtime()), 0, 8)),
            'estado' => true,
        ]);
    }

    private function ensureOrgTipos(): void
    {
        foreach ([
            Organizacion::TIPO_ASOCIACION => 'Asociación',
            Organizacion::TIPO_IGLESIA => 'Iglesia',
        ] as $id => $nombre) {
            if (DB::table('tipo_organizacion')->where('id', $id)->exists()) {
                continue;
            }

            DB::table('tipo_organizacion')->insert([
                'id' => $id,
                'tipo_organizacion_padre_id' => null,
                'nombre' => $nombre,
                'descripcion' => $nombre,
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
