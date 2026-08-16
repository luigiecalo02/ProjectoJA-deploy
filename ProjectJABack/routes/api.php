<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require app_path('Modules/Auth/Routes/api.php');
    require app_path('Modules/Users/Routes/api.php');
    require app_path('Modules/Events/Routes/api.php');
    require app_path('Modules/Clubs/Routes/api.php');
    require app_path('Modules/Organizations/Routes/api.php');
    require app_path('Modules/Terrains/Routes/api.php');
    require app_path('Modules/Cabanas/Routes/api.php');
});
