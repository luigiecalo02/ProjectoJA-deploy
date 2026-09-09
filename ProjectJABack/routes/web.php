<?php

use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/storage/{path}', function (string $path, PublicFileService $files) {
    return $files->stream($path);
})->where('path', '.*');
