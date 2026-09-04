import type {
  ClubEvent,
  JudgeBoard,
  JudgeCalificacion,
  JudgeClub,
  JudgeSubevento,
} from '@/modules/events/types'

export type FieldOutboxStatus = 'pending' | 'syncing' | 'failed'

export interface FieldActivitySlice {
  actividad_id: number
  subevento_id: number
  subevento: JudgeSubevento
  actividad: JudgeSubevento
  clubes: JudgeClub[]
}

export interface FieldEventPack {
  event: ClubEvent
  board: JudgeBoard
  activities: FieldActivitySlice[]
}

export interface FieldOfflinePack {
  downloaded_at: string
  events: FieldEventPack[]
}

export interface FieldPackRecord {
  userId: number
  downloadedAt: string
  pack: FieldOfflinePack
}

export interface FieldScorePayload {
  organizacion_id: number
  puntaje_obtenido?: number | null
  observaciones?: string | null
  criterios?: Array<{ criterio_evaluacion_id: number; puntos: number }>
  puesto_entrega?: string | null
  tiempo_entrega?: string | null
  resultado_obtenido?: number | null
}

export interface FieldOutboxItem {
  id: string
  userId: number
  rootEventId: number
  actividadId: number
  payload: FieldScorePayload
  status: FieldOutboxStatus
  error?: string | null
  createdAt: string
  updatedAt: string
}

export interface FieldOptimisticScore {
  calificacion: JudgeCalificacion
}
