<?php

use App\Modules\Settings\Http\Controllers\BrandSettingsController;
use App\Modules\Settings\Http\Controllers\ClubesAttendanceController;
use App\Modules\Settings\Http\Controllers\ClubesSettingsController;
use App\Modules\Settings\Http\Controllers\ClubesSignupController;
use App\Modules\Settings\Http\Controllers\CuentaBancariaController;
use App\Modules\Settings\Http\Controllers\MailSettingsController;
use App\Modules\Settings\Http\Controllers\PublicFormSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('settings/brand', [BrandSettingsController::class, 'show']);
Route::get('settings/brand/file/{path}', [BrandSettingsController::class, 'file'])
    ->where('path', '.*');
Route::get('settings/clubes/public', [ClubesSettingsController::class, 'publicShow']);
Route::get('settings/clubes/public/organizaciones', [ClubesSignupController::class, 'organizaciones']);
Route::post('settings/clubes/public/register', [ClubesSignupController::class, 'register'])
    ->middleware('throttle:5,1');
Route::get('settings/clubes/public/activate', [ClubesSignupController::class, 'inviteShow'])
    ->middleware('throttle:20,1');
Route::post('settings/clubes/public/activate/lookup', [ClubesSignupController::class, 'inviteLookup'])
    ->middleware('throttle:10,1');
Route::post('settings/clubes/public/activate', [ClubesSignupController::class, 'inviteActivate'])
    ->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('settings/clubes', [ClubesSettingsController::class, 'show']);
    Route::post('settings/clubes/invite-link', [ClubesSignupController::class, 'createInvite']);
    Route::put('settings/clubes', [ClubesSettingsController::class, 'update']);
    Route::post('settings/clubes/assets/{asset}', [ClubesSettingsController::class, 'uploadAsset']);
    Route::delete('settings/clubes/assets/{asset}', [ClubesSettingsController::class, 'resetAsset']);
    Route::post('settings/clubes/events', [ClubesSettingsController::class, 'storeEvent']);
    Route::post('settings/clubes/events/{event}', [ClubesSettingsController::class, 'updateEvent']);
    Route::get('settings/clubes/asistencia/eventos', [ClubesAttendanceController::class, 'events']);
    Route::get('settings/clubes/asistencia/resumen', [ClubesAttendanceController::class, 'ranking']);
    Route::get('settings/clubes/asistencia/{event}', [ClubesAttendanceController::class, 'show']);
    Route::put('settings/clubes/asistencia/{event}', [ClubesAttendanceController::class, 'sync']);
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
