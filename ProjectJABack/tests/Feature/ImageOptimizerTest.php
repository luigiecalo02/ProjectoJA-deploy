<?php

namespace Tests\Feature;

use App\Modules\Shared\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizerTest extends TestCase
{
    public function test_reduce_foto_grande_a_jpeg(): void
    {
        Storage::fake('public');

        $stored = app(ImageOptimizer::class)->store(
            UploadedFile::fake()->image('foto.png', 2400, 1800),
            'tests',
            'img',
        );

        $this->assertTrue(str_ends_with($stored->path, '.jpg'));
        $this->assertSame('image/jpeg', $stored->mime);
        $this->assertGreaterThan(0, $stored->size);
        Storage::disk('public')->assertExists($stored->path);
    }

    public function test_deja_pasar_archivos_que_no_son_foto(): void
    {
        Storage::fake('public');

        $stored = app(ImageOptimizer::class)->store(
            UploadedFile::fake()->create('recibo.pdf', 80, 'application/pdf'),
            'tests',
            'doc',
        );

        $this->assertFalse(str_ends_with($stored->path, '.jpg'));
        Storage::disk('public')->assertExists($stored->path);
    }
}
