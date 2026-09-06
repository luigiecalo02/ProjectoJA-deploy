import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { useOnline } from '@vueuse/core'
import { fieldModeService } from '@/services/fieldModeService'
import { useAuthStore } from '@/stores/auth'
import { prepareUploadFile } from '@/utils/optimizeImage'
import type { ClubEvent, JudgeBoard, JudgeCalificacion } from '@/modules/events/types'
import type { FieldEventPack, FieldPhotoOutboxItem, FieldScorePayload } from '@/modules/fieldMode/types'

export const useFieldModeStore = defineStore('fieldMode', () => {
  const auth = useAuthStore()
  const online = useOnline()

  const downloading = ref(false)
  const downloadingEventId = ref<number | null>(null)
  const syncing = ref(false)
  const pendingCount = ref(0)
  const failedCount = ref(0)
  const pendingByEvent = ref<Record<number, number>>({})
  const uploadedByEvent = ref<Record<number, number>>({})
  const lastDownloadedAt = ref<string | null>(null)
  const deviceLabel = ref<string | null>(null)
  const lastSyncError = ref<string | null>(null)
  const initialized = ref(false)

  const canUseFieldMode = computed(() => auth.hasPermission('events.evaluate'))
  const hasPending = computed(() => pendingCount.value > 0 || failedCount.value > 0)

  function pendingForEvent(eventId: number): number {
    return pendingByEvent.value[eventId] ?? 0
  }

  function uploadedForEvent(eventId: number): number {
    return uploadedByEvent.value[eventId] ?? 0
  }

  async function refreshMeta(): Promise<void> {
    const userId = auth.user?.id
    if (!userId) {
      pendingCount.value = 0
      failedCount.value = 0
      pendingByEvent.value = {}
      uploadedByEvent.value = {}
      lastDownloadedAt.value = null
      deviceLabel.value = null
      return
    }
    const [counts, downloadedAt, uploaded, device] = await Promise.all([
      fieldModeService.outboxCounts(userId),
      fieldModeService.lastDownloadedAt(userId),
      fieldModeService.uploadedCounts(userId),
      fieldModeService.packDevice(userId),
    ])
    pendingCount.value = counts.pending
    failedCount.value = counts.failed
    pendingByEvent.value = counts.byEvent
    uploadedByEvent.value = uploaded
    lastDownloadedAt.value = downloadedAt ?? device.downloadedAt
    deviceLabel.value = device.deviceLabel
  }

  async function downloadPack(eventId?: number): Promise<number> {
    const userId = auth.user?.id
    if (!userId) throw new Error('Sesión no disponible')
    downloading.value = true
    downloadingEventId.value = eventId ?? null
    lastSyncError.value = null
    try {
      const pack = await fieldModeService.downloadPack(userId, eventId)
      lastDownloadedAt.value = pack.downloaded_at
      await refreshMeta()
      return pack.events.length
    } finally {
      downloading.value = false
      downloadingEventId.value = null
    }
  }

  async function cachedEvents(): Promise<ClubEvent[]> {
    const userId = auth.user?.id
    if (!userId) return []
    return fieldModeService.cachedEvents(userId)
  }

  async function cachedPack() {
    const userId = auth.user?.id
    if (!userId) return null
    return fieldModeService.cachedPack(userId)
  }

  async function cachedEventPack(eventId: number): Promise<FieldEventPack | null> {
    const pack = await cachedPack()
    return pack?.events.find((item) => item.event.id === eventId) ?? null
  }

  async function getJudgeBoard(
    eventId: number,
    subeventoId?: number | null,
    actividadId?: number | null,
  ): Promise<JudgeBoard> {
    const userId = auth.user?.id
    if (!userId) throw new Error('Sesión no disponible')
    return fieldModeService.getJudgeBoard(userId, eventId, subeventoId, actividadId)
  }

  async function saveCalificacion(
    rootEventId: number,
    actividadId: number,
    payload: FieldScorePayload,
  ): Promise<{ calificacion: JudgeCalificacion; queued: boolean }> {
    const userId = auth.user?.id
    if (!userId) throw new Error('Sesión no disponible')
    const result = await fieldModeService.saveCalificacion(
      userId,
      rootEventId,
      actividadId,
      payload,
      online.value,
    )
    await refreshMeta()
    return result
  }

  async function enqueuePhoto(
    rootEventId: number,
    actividadId: number,
    organizacionId: number,
    file: File,
  ): Promise<FieldPhotoOutboxItem> {
    const userId = auth.user?.id
    if (!userId) throw new Error('Sesión no disponible')
    if (!file.type.startsWith('image/')) {
      throw new Error('Solo se pueden guardar fotos en el teléfono. Video y audio requieren internet.')
    }
    const optimized = await prepareUploadFile(file)
    const item = await fieldModeService.enqueuePhoto(
      userId,
      rootEventId,
      actividadId,
      organizacionId,
      optimized,
    )
    await refreshMeta()
    return item
  }

  async function cachedPhotos(
    rootEventId?: number,
    actividadId?: number,
    organizacionId?: number,
  ): Promise<FieldPhotoOutboxItem[]> {
    const userId = auth.user?.id
    if (!userId) return []
    return fieldModeService.cachedPhotos(userId, rootEventId, actividadId, organizacionId)
  }

  async function syncPending(eventId?: number): Promise<{ synced: number; failed: number }> {
    const empty = { synced: 0, failed: 0 }
    const userId = auth.user?.id
    if (!userId || !online.value || syncing.value) return empty
    syncing.value = true
    lastSyncError.value = null
    try {
      const result = await fieldModeService.syncOutbox(userId, eventId)
      if (result.failed > 0) {
        lastSyncError.value = 'Algunas calificaciones no se pudieron sincronizar.'
      }
      await refreshMeta()
      return result
    } catch (error) {
      lastSyncError.value = error instanceof Error ? error.message : 'Error al sincronizar'
      return empty
    } finally {
      syncing.value = false
    }
  }

  async function init(): Promise<void> {
    if (initialized.value) return
    initialized.value = true
    await refreshMeta()
    if (online.value && hasPending.value) {
      void syncPending()
    }
  }

  watch(online, (isOnline) => {
    if (isOnline && (pendingCount.value > 0 || failedCount.value > 0)) {
      void syncPending()
    }
  })

  return {
    online,
    downloading,
    downloadingEventId,
    syncing,
    pendingCount,
    failedCount,
    pendingByEvent,
    pendingForEvent,
    uploadedForEvent,
    lastDownloadedAt,
    deviceLabel,
    lastSyncError,
    canUseFieldMode,
    hasPending,
    init,
    refreshMeta,
    downloadPack,
    cachedEvents,
    cachedPack,
    cachedEventPack,
    getJudgeBoard,
    saveCalificacion,
    enqueuePhoto,
    cachedPhotos,
    syncPending,
  }
})
