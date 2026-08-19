<?php

namespace App\Modules\Shared\Services;

final class OptimizedStoredFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $size,
        public readonly string $mime,
        public readonly ?string $hash,
    ) {}
}
