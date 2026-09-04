import type { JudgeBoard, JudgeCalificacion, JudgeClub, JudgeClubResumen } from '@/modules/events/types'
import type { FieldEventPack, FieldScorePayload } from '@/modules/fieldMode/types'

function clone<T>(value: T): T {
  return structuredClone(value)
}

export function boardFromPack(
  eventPack: FieldEventPack,
  subeventoId?: number | null,
  actividadId?: number | null,
): JudgeBoard {
  const board = clone(eventPack.board)
  const targetId = actividadId || subeventoId
  if (!targetId) return board

  const slice =
    eventPack.activities.find((item) => item.actividad_id === targetId)
    || eventPack.activities.find((item) => item.subevento_id === targetId)
    || (subeventoId
      ? eventPack.activities.find((item) => item.subevento_id === subeventoId)
      : undefined)

  if (!slice) return board

  board.subevento = clone(slice.subevento)
  board.actividad = clone(slice.actividad)
  board.clubes = clone(slice.clubes)
  return board
}

export function applyScoreToEventPack(
  eventPack: FieldEventPack,
  actividadId: number,
  payload: FieldScorePayload,
  calificacion: JudgeCalificacion,
): FieldEventPack {
  const next = clone(eventPack)
  const orgId = payload.organizacion_id
  const score = Number(calificacion.puntaje_obtenido) || 0

  const patchClubes = (clubes: JudgeClub[]): JudgeClub[] =>
    clubes.map((club) => {
      if (club.organizacion_id !== orgId) return club
      const max = club.puntaje_maximo ?? next.activities.find((a) => a.actividad_id === actividadId)?.actividad.puntaje_maximo
      return {
        ...club,
        estado: 'evaluado',
        puntaje_obtenido: score,
        porcentaje: max ? Math.round((score / max) * 100) : club.porcentaje,
        calificacion: {
          ...calificacion,
          observaciones_director: club.calificacion?.observaciones_director ?? club.observaciones_director,
          observaciones_director_updated_at:
            club.calificacion?.observaciones_director_updated_at ?? club.observaciones_director_updated_at,
        },
      }
    })

  next.activities = next.activities.map((slice) => {
    if (slice.actividad_id !== actividadId) return slice
    return { ...slice, clubes: patchClubes(slice.clubes) }
  })

  if (next.board.actividad?.id === actividadId) {
    next.board.clubes = patchClubes(next.board.clubes)
  }

  const orgKey = String(orgId)
  const actKey = String(actividadId)
  next.board.evaluados = {
    ...(next.board.evaluados ?? {}),
    [orgKey]: {
      ...(next.board.evaluados?.[orgKey] ?? {}),
      [actKey]: score,
    },
  }

  if (next.board.pendientes?.[orgKey]?.[actKey] != null) {
    const orgPend = { ...next.board.pendientes[orgKey] }
    delete orgPend[actKey]
    next.board.pendientes = { ...next.board.pendientes, [orgKey]: orgPend }
  }

  next.board.clubes_resumen = (next.board.clubes_resumen ?? []).map((club): JudgeClubResumen => {
    if (club.organizacion_id !== orgId) return club
    const wasPending = club.estado !== 'evaluado'
    return {
      ...club,
      estado: 'evaluado',
      eventos_evaluados: wasPending ? club.eventos_evaluados + 1 : club.eventos_evaluados,
      eventos_pendientes: wasPending ? Math.max(0, club.eventos_pendientes - 1) : club.eventos_pendientes,
    }
  })

  const resumen = next.board.clubes_resumen ?? []
  if (resumen.length) {
    const pendientes = resumen.filter((club) => (club.eventos_pendientes ?? 0) > 0).length
    const total = resumen.length
    next.board.progreso = {
      evaluados: Math.max(0, total - pendientes),
      pendientes,
      total,
      pct: total ? Math.round(((total - pendientes) / total) * 100) : 0,
    }
  }

  return next
}

export function optimisticCalificacion(
  actividadId: number,
  payload: FieldScorePayload,
  previous?: JudgeCalificacion | null,
): JudgeCalificacion {
  return {
    id: previous?.id ?? -Date.now(),
    evento_id: actividadId,
    organizacion_id: payload.organizacion_id,
    puntaje_obtenido: Number(payload.puntaje_obtenido) || 0,
    observaciones: payload.observaciones ?? null,
    puesto_entrega: payload.puesto_entrega ?? null,
    tiempo_entrega: payload.tiempo_entrega ?? null,
    resultado_obtenido: payload.resultado_obtenido ?? null,
    detalles: payload.criterios ?? previous?.detalles ?? [],
    updated_at: new Date().toISOString(),
    observaciones_director: previous?.observaciones_director,
    observaciones_director_updated_at: previous?.observaciones_director_updated_at,
  }
}
