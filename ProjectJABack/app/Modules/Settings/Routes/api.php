<?php

use App\Modules\Settings\Http\Controllers\BrandSettingsController;
use App\Modules\Settings\Http\Controllers\CuentaBancariaController;
use App\Modules\Settings\Http\Controllers\MailSettingsController;
use App\Modules\Settings\Http\Controllers\PublicFormSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('settings/brand', [BrandSettingsController::class, 'show']);
Route::get('settings/brand/file/{path}', [BrandSettingsController::class, 'file'])
    ->where('path', '.*');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('settings/mail', [MailSettingsController::class, 'show']);
    Route::put('settings/mail', [MailSettingsController::class, 'update']);
    Route::post('settings/mail/test', [MailSettingsController::class, 'test']);
    Route::get('settings/public-form', [PublicFormSettingsController::class, 'show']);
    Route::put('settings/public-form', [PublicFormSettingsController::class, 'update']);
    Route::get('settings/cuentas-bancarias', [CuentaBancariaController::class, 'index']);
    Route::post('settings/cuentas-bancarias', [CuentaBancariaController::class, 'store']);
    Route::put('settings/cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'update']);
    Route::delete('settings/cuentas-bancarias/{cuentaBancaria}', [CuentaBancariaController::class, 'destroy']);
    Route::post('settings/cuentas-bancarias/{cuentaBancaria}/qr', [CuentaBancariaController::class, 'uploadQr']);
    Route::delete('settings/cuentas-bancarias/{cuentaBancaria}/qr', [CuentaBancariaController::class, 'deleteQr']);
    Route::put('settings/brand/hero-fit', [BrandSettingsController::class, 'updateHeroFit']);
    Route::put('settings/brand/hero-copy', [BrandSettingsController::class, 'updateHeroCopy']);
    Route::put('settings/brand/loaders/{key}', [BrandSettingsController::class, 'updateLoader']);
    Route::post('settings/brand/loaders/{key}/logo', [BrandSettingsController::class, 'uploadLoaderLogo']);
    Route::delete('settings/brand/loaders/{key}/logo', [BrandSettingsController::class, 'resetLoaderLogo']);
    Route::delete('settings/brand/loaders/{key}', [BrandSettingsController::class, 'resetLoader']);
    Route::post('settings/brand/{asset}', [BrandSettingsController::class, 'upload']);
    Route::delete('settings/brand/{asset}', [BrandSettingsController::class, 'reset']);
});
