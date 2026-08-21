<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/{path}', function (string $path) {
    $normalized = str_replace('\\', '/', $path);
    if ($normalized === '' || str_contains($normalized, '..')) {
        abort(404);
    }

    abort_unless(Storage::disk('public')->exists($normalized), 404);

    return Storage::disk('public')->response($normalized);
})->where('path', '.*');
