<?php

use App\Modules\Settings\Http\Controllers\BrandSettingsController;
use App\Modules\Settings\Http\Controllers\ClubesAbonosController;
use App\Modules\Settings\Http\Controllers\ClubesAttendanceController;
use App\Modules\Settings\Http\Controllers\ClubesGananciasController;
use App\Modules\Settings\Http\Controllers\ClubesInscripcionesController;
use App\Modules\Settings\Http\Controllers\ClubesPresupuestoController;
use App\Modules\Settings\Http\Controllers\ClubesParticipantesController;
use App\Modules\Settings\Http\Controllers\ClubesServiciosController;
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
    Route::get('settings/clubes/inscripciones/{evento}/yo', [ClubesInscripcionesController::class, 'me']);
    Route::put('settings/clubes/inscripciones/{evento}/yo', [ClubesInscripcionesController::class, 'join']);
    Route::get('settings/clubes/inscripciones/{evento}', [ClubesInscripcionesController::class, 'show']);
    Route::put('settings/clubes/inscripciones/{evento}', [ClubesInscripcionesController::class, 'sync']);
    Route::get('settings/clubes/asistencia/eventos', [ClubesAttendanceController::class, 'events']);
    Route::get('settings/clubes/asistencia/resumen', [ClubesAttendanceController::class, 'ranking']);
    Route::get('settings/clubes/asistencia/integrante/{persona}', [ClubesAttendanceController::class, 'member']);
    Route::get('settings/clubes/asistencia/{event}', [ClubesAttendanceController::class, 'show']);
    Route::put('settings/clubes/asistencia/{event}', [ClubesAttendanceController::class, 'sync']);
    Route::get('settings/clubes/ganancias', [ClubesGananciasController::class, 'index']);
    Route::post('settings/clubes/ganancias', [ClubesGananciasController::class, 'store']);
    Route::get('settings/clubes/ganancias/eclesiasticas', [ClubesGananciasController::class, 'indexEclesiasticas']);
    Route::post('settings/clubes/ganancias/eclesiasticas', [ClubesGananciasController::class, 'storeEclesiastica']);
    Route::put('settings/clubes/ganancias/eclesiasticas/{eclesiastica}', [ClubesGananciasController::class, 'updateEclesiastica']);
    Route::delete('settings/clubes/ganancias/eclesiasticas/{eclesiastica}', [ClubesGananciasController::class, 'destroyEclesiastica']);
    Route::put('settings/clubes/ganancias/{distribucion}', [ClubesGananciasController::class, 'update']);
    Route::delete('settings/clubes/ganancias/{distribucion}', [ClubesGananciasController::class, 'destroy']);
    Route::get('settings/clubes/abonos', [ClubesAbonosController::class, 'board']);
    Route::post('settings/clubes/abonos', [ClubesAbonosController::class, 'store']);
    Route::get('settings/clubes/presupuesto/eventos', [ClubesPresupuestoController::class, 'eventos']);
    Route::get('settings/clubes/presupuesto/{evento}', [ClubesPresupuestoController::class, 'show']);
    Route::put('settings/clubes/presupuesto/{evento}', [ClubesPresupuestoController::class, 'update']);
    Route::delete('settings/clubes/presupuesto/{evento}/{presupuesto}', [ClubesPresupuestoController::class, 'destroy']);
    Route::get('settings/clubes/servicios', [ClubesServiciosController::class, 'index']);
    Route::get('settings/clubes/servicios/iconos', [ClubesServiciosController::class, 'iconos']);
    Route::post('settings/clubes/servicios', [ClubesServiciosController::class, 'store']);
    Route::put('settings/clubes/servicios/{servicio}', [ClubesServiciosController::class, 'update']);
    Route::post('settings/clubes/servicios/{servicio}', [ClubesServiciosController::class, 'update']);
    Route::delete('settings/clubes/servicios/{servicio}', [ClubesServiciosController::class, 'destroy']);
    Route::get('settings/clubes/events/{event}/servicios', [ClubesServiciosController::class, 'ofertas']);
    Route::put('settings/clubes/events/{event}/servicios', [ClubesServiciosController::class, 'syncOfertas']);
    Route::get('settings/clubes/events/{event}/participantes/yo', [ClubesParticipantesController::class, 'me']);
    Route::put('settings/clubes/events/{event}/participantes/yo', [ClubesParticipantesController::class, 'join']);
    Route::get('settings/clubes/events/{event}/participantes', [ClubesParticipantesController::class, 'show']);
    Route::put('settings/clubes/events/{event}/participantes', [ClubesParticipantesController::class, 'sync']);
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
