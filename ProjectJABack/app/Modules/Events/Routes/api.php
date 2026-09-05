<?php

use App\Modules\Events\Http\Controllers\CategoriaSubeventoController;
use App\Modules\Events\Http\Controllers\CriterioEvaluacionController;
use App\Modules\Events\Http\Controllers\EventArchivoController;
use App\Modules\Events\Http\Controllers\EventController;
use App\Modules\Events\Http\Controllers\EventEconomiaController;
use App\Modules\Events\Http\Controllers\EventJudgeController;
use App\Modules\Events\Http\Controllers\EventParticipationController;
use App\Modules\Events\Http\Controllers\EventStandingsController;
use App\Modules\Events\Http\Controllers\PublicEventoController;
use App\Modules\Events\Http\Controllers\SeguroConsultaController;
use Illuminate\Support\Facades\Route;

Route::prefix('public/eventos')->group(function () {
    Route::get('/', [PublicEventoController::class, 'index'])->middleware('throttle:30,1');
    Route::get('{event}', [PublicEventoController::class, 'show'])->middleware('throttle:30,1');
    Route::post('{event}/inscribir', [PublicEventoController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('events/tipos', [EventController::class, 'tipos']);
    Route::get('events/jueces', [EventController::class, 'jueces']);
    Route::get('events/supervisores', [EventController::class, 'supervisores']);
    Route::get('events/tipos-seguro', [EventEconomiaController::class, 'tiposSeguro']);
    Route::get('events/productos-servicios', [EventEconomiaController::class, 'productos']);
    Route::get('events/seguros/consulta', [SeguroConsultaController::class, 'index']);
    Route::post('events/productos-servicios', [EventEconomiaController::class, 'storeProducto']);
    Route::put('events/productos-servicios/{productoServicio}', [EventEconomiaController::class, 'updateProducto']);
    Route::patch('events/productos-servicios/{productoServicio}', [EventEconomiaController::class, 'updateProducto']);

    Route::get('events/categorias-subevento', [CategoriaSubeventoController::class, 'index']);
    Route::post('events/categorias-subevento', [CategoriaSubeventoController::class, 'store']);
    Route::put('events/categorias-subevento/{categoriaSubevento}', [CategoriaSubeventoController::class, 'update']);
    Route::patch('events/categorias-subevento/{categoriaSubevento}', [CategoriaSubeventoController::class, 'update']);
    Route::delete('events/categorias-subevento/{categoriaSubevento}', [CategoriaSubeventoController::class, 'destroy']);

    Route::get('events/criterios-evaluacion', [CriterioEvaluacionController::class, 'index']);
    Route::post('events/criterios-evaluacion', [CriterioEvaluacionController::class, 'store']);
    Route::put('events/criterios-evaluacion/{criterioEvaluacion}', [CriterioEvaluacionController::class, 'update']);
    Route::patch('events/criterios-evaluacion/{criterioEvaluacion}', [CriterioEvaluacionController::class, 'update']);
    Route::delete('events/criterios-evaluacion/{criterioEvaluacion}', [CriterioEvaluacionController::class, 'destroy']);

    Route::get('events', [EventController::class, 'index']);
    Route::post('events', [EventController::class, 'store']);
    Route::get('events/{event}/participation', [EventParticipationController::class, 'show']);
    Route::get('events/{event}/roster-cobertura', [EventEconomiaController::class, 'rosterCobertura']);
    Route::get('events/{event}/acompanantes/personas', [EventEconomiaController::class, 'companionPersonas']);
    Route::post('events/{event}/acompanantes/personas', [EventEconomiaController::class, 'storeCompanionPersona']);
    Route::post('events/{event}/enroll', [EventEconomiaController::class, 'enroll']);
    Route::get('events/{event}/inscripciones-revision', [EventEconomiaController::class, 'listRevision']);
    Route::get('events/{event}/productos-servicios', [EventEconomiaController::class, 'ofertasEvento']);
    Route::put('events/{event}/productos-servicios', [EventEconomiaController::class, 'syncOfertasEvento']);
    Route::get('events/{event}/actividad-participantes', [EventParticipationController::class, 'activityRoster']);
    Route::put('events/{event}/actividad-participantes', [EventParticipationController::class, 'syncActivityRoster']);
    Route::post('events/{event}/evidencias', [EventParticipationController::class, 'storeEvidencia']);
    Route::delete('events/evidencias/{eventoEvidencia}', [EventParticipationController::class, 'destroyEvidencia']);
    Route::post('events/{event}/observacion-director', [EventParticipationController::class, 'storeDirectorObservacion']);
    Route::get('events/judge/offline-pack', [EventJudgeController::class, 'offlinePack']);
    Route::get('events/{event}/judge', [EventJudgeController::class, 'show']);
    Route::get('events/{event}/judge/evaluaciones', [EventJudgeController::class, 'evaluaciones']);
    Route::post('events/{event}/calificaciones', [EventJudgeController::class, 'storeScore']);
    Route::get('events/{event}/standings', [EventStandingsController::class, 'show']);
    Route::get('events/{event}/standings-tree', [EventStandingsController::class, 'tree']);
    Route::get('events/{event}', [EventController::class, 'show']);
    Route::put('events/{event}', [EventController::class, 'update']);
    Route::patch('events/{event}', [EventController::class, 'update']);
    Route::post('events/{event}/jueces-conflictos', [EventController::class, 'resolveJuezConflicts']);
    Route::patch('events/{event}/estado', [EventController::class, 'updateEstado']);
    Route::post('events/{event}/reorder-children', [EventController::class, 'reorderChildren']);
    Route::post('events/{event}/move', [EventController::class, 'move']);
    Route::post('events/{event}/duplicate', [EventController::class, 'duplicate']);
    Route::delete('events/{event}', [EventController::class, 'destroy']);
    Route::post('events/{event}/image', [EventController::class, 'image']);
    Route::post('events/{event}/banner', [EventController::class, 'banner']);
    Route::get('events/{event}/archivos', [EventArchivoController::class, 'index']);
    Route::post('events/{event}/archivos', [EventArchivoController::class, 'store']);
    Route::delete('events/{event}/archivos/{archivo}', [EventArchivoController::class, 'destroy']);

    Route::get('evento-inscripciones/{eventoInscripcion}', [EventEconomiaController::class, 'showInscripcion']);
    Route::post('evento-inscripciones/{eventoInscripcion}/comprobantes', [EventEconomiaController::class, 'storeComprobante']);
    Route::post('evento-inscripcion-comprobantes/{comprobante}/reemplazo', [EventEconomiaController::class, 'replaceComprobante']);
    Route::post('evento-inscripcion-comprobantes/{comprobante}/comentarios', [EventEconomiaController::class, 'storeComprobanteComentario']);
    Route::patch('evento-inscripciones/{eventoInscripcion}/revision', [EventEconomiaController::class, 'reviewInscripcion']);
    Route::delete('evento-inscripcion-comprobantes/{comprobante}', [EventEconomiaController::class, 'destroyComprobante']);
    Route::patch('evento-inscripcion-comprobantes/{comprobante}', [EventEconomiaController::class, 'reviewComprobante']);
});
