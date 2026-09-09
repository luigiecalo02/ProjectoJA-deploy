<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Modules\Shared\Services\PublicFileService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicFileController
{
    public function __construct(private readonly PublicFileService $publicFiles) {}

    public function show(string $path): StreamedResponse
    {
        return $this->publicFiles->stream($path);
    }
}
