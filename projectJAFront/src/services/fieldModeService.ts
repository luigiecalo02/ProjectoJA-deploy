import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage, isNetworkError } from '@/services/api'
import {
  deleteOutboxItem,
  getFieldPack,
  listOutbox,
  outboxKey,
  putOutboxItem,
  saveFieldPack,
} from '@/modules/fieldMode/db'
import { applyScoreToEventPack, boardFromPack, optimisticCalificacion } from '@/modules/fieldMode/packBoard'
import type { ClubEvent, JudgeBoard, JudgeCalificacion } from '@/modules/events/types'
import type { FieldEventPack, FieldOfflinePack, FieldOutboxItem, FieldScorePayload } from '@/modules/fieldMode/types'

export class FieldPackMissingError extends Error {
  constructor(message = 'No hay un paquete de campo descargado.') {
    super(message)
    this.name = 'FieldPackMissingError'
  }
}

let syncing = false

async function persistEventPack(
  userId: number,
  rootEventId: number,
  updater: (eventPack: FieldEventPack) => FieldEventPack,
): Promise<void> {
  const record = await getFieldPack(userId)
  if (!record) return
  const events = record.pack.events.map((item) =>
    item.event.id === rootEventId ? updater(item) : item,
  )
  await saveFieldPack(userId, { ...record.pack, events })
}

export const fieldModeService = {
  async downloadPack(userId: number, eventId?: number): Promise<FieldOfflinePack> {
    const incoming = await eventsService.judgeOfflinePack(eventId)
    if (!eventId) {
      await saveFieldPack(userId, incoming)
      return incoming
    }

    const existing = await getFieldPack(userId)
    if (!existing) {
      await saveFieldPack(userId, incoming)
      return incoming
    }

    const byId = new Map(existing.pack.events.map((item) => [item.event.id, item]))
    for (const item of incoming.events) {
      byId.set(item.event.id, item)
    }
    const merged: FieldOfflinePack = {
      downloaded_at: incoming.downloaded_at,
      events: [...byId.values()],
    }
    await saveFieldPack(userId, merged)
    return incoming
  },

  async cachedPack(userId: number): Promise<FieldOfflinePack | null> {
    const record = await getFieldPack(userId)
    return record?.pack ?? null
  },

  async cachedEvents(userId: number): Promise<ClubEvent[]> {
    const pack = await this.cachedPack(userId)
    return pack?.events.map((item) => item.event) ?? []
  },

  async lastDownloadedAt(userId: number): Promise<string | null> {
    const record = await getFieldPack(userId)
    return record?.downloadedAt ?? null
  },

  async getJudgeBoard(
    userId: number,
    eventId: number,
    subeventoId?: number | null,
    actividadId?: number | null,
  ): Promise<JudgeBoard> {
    const pack = await this.cachedPack(userId)
    const eventPack = pack?.events.find((item) => item.event.id === eventId)
    if (!eventPack) {
      throw new FieldPackMissingError()
    }
    return boardFromPack(eventPack, subeventoId, actividadId)
  },

  async enqueueScore(
    userId: number,
    rootEventId: number,
    actividadId: number,
    payload: FieldScorePayload,
  ): Promise<JudgeCalificacion> {
    const pack = await this.cachedPack(userId)
    const eventPack = pack?.events.find((item) => item.event.id === rootEventId)
    const slice = eventPack?.activities.find((item) => item.actividad_id === actividadId)
    const previous = slice?.clubes.find((club) => club.organizacion_id === payload.organizacion_id)?.calificacion
    const calificacion = optimisticCalificacion(actividadId, payload, previous)
    const now = new Date().toISOString()
    const item: FieldOutboxItem = {
      id: outboxKey(userId, actividadId, payload.organizacion_id),
      userId,
      rootEventId,
      actividadId,
      payload,
      status: 'pending',
      error: null,
      createdAt: now,
      updatedAt: now,
    }

    await putOutboxItem(item)
    if (eventPack) {
      await persistEventPack(userId, rootEventId, (row) =>
        applyScoreToEventPack(row, actividadId, payload, calificacion),
      )
    }
    return calificacion
  },

  async saveCalificacion(
    userId: number,
    rootEventId: number,
    actividadId: number,
    payload: FieldScorePayload,
    online: boolean,
  ): Promise<{ calificacion: JudgeCalificacion; queued: boolean }> {
    if (online) {
      try {
        const saved = await eventsService.saveCalificacion(actividadId, payload)
        await persistEventPack(userId, rootEventId, (row) =>
          applyScoreToEventPack(row, actividadId, payload, saved),
        )
        await deleteOutboxItem(outboxKey(userId, actividadId, payload.organizacion_id)).catch(() => undefined)
        return { calificacion: saved, queued: false }
      } catch (error) {
        if (!isNetworkError(error)) throw error
      }
    }

    const calificacion = await this.enqueueScore(userId, rootEventId, actividadId, payload)
    return { calificacion, queued: true }
  },

  async outboxCounts(userId: number): Promise<{
    pending: number
    failed: number
    byEvent: Record<number, number>
  }> {
    const items = await listOutbox(userId)
    const byEvent: Record<number, number> = {}
    let pending = 0
    let failed = 0
    for (const item of items) {
      if (item.status === 'failed') failed += 1
      if (item.status === 'pending' || item.status === 'syncing' || item.status === 'failed') {
        pending += item.status === 'failed' ? 0 : 1
        byEvent[item.rootEventId] = (byEvent[item.rootEventId] ?? 0) + 1
      }
    }
    return { pending, failed, byEvent }
  },

  async syncOutbox(userId: number, eventId?: number): Promise<{ synced: number; failed: number }> {
    if (syncing) return { synced: 0, failed: 0 }
    syncing = true
    let synced = 0
    let failed = 0
    try {
      const items = (await listOutbox(userId)).filter(
        (item) => eventId == null || item.rootEventId === eventId,
      )
      for (const item of items) {
        if (item.status !== 'pending' && item.status !== 'failed') continue
        const working: FieldOutboxItem = {
          ...item,
          status: 'syncing',
          updatedAt: new Date().toISOString(),
        }
        await putOutboxItem(working)
        try {
          await eventsService.saveCalificacion(item.actividadId, item.payload)
          await deleteOutboxItem(item.id)
          synced += 1
        } catch (error) {
          if (isNetworkError(error)) {
            await putOutboxItem({ ...item, status: 'pending', updatedAt: new Date().toISOString() })
            break
          }
          failed += 1
          await putOutboxItem({
            ...item,
            status: 'failed',
            error: getApiErrorMessage(error, 'No se pudo sincronizar la calificación'),
            updatedAt: new Date().toISOString(),
          })
        }
      }
    } finally {
      syncing = false
    }
    return { synced, failed }
  },
}
