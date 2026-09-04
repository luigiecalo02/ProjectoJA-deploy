import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import { useOnline } from '@vueuse/core'
import { fieldModeService } from '@/services/fieldModeService'
import { useAuthStore } from '@/stores/auth'
import type { ClubEvent, JudgeBoard, JudgeCalificacion } from '@/modules/events/types'
import type { FieldScorePayload } from '@/modules/fieldMode/types'

export const useFieldModeStore = defineStore('fieldMode', () => {
  const auth = useAuthStore()
  const online = useOnline()

  const downloading = ref(false)
  const downloadingEventId = ref<number | null>(null)
  const syncing = ref(false)
  const pendingCount = ref(0)
  const failedCount = ref(0)
  const lastDownloadedAt = ref<string | null>(null)
  const lastSyncError = ref<string | null>(null)
  const initialized = ref(false)

  const canUseFieldMode = computed(() => auth.hasPermission('events.evaluate'))
  const hasPending = computed(() => pendingCount.value > 0 || failedCount.value > 0)

  async function refreshMeta(): Promise<void> {
    const userId = auth.user?.id
    if (!userId) {
      pendingCount.value = 0
      failedCount.value = 0
      lastDownloadedAt.value = null
      return
    }
    const [counts, downloadedAt] = await Promise.all([
      fieldModeService.outboxCounts(userId),
      fieldModeService.lastDownloadedAt(userId),
    ])
    pendingCount.value = counts.pending
    failedCount.value = counts.failed
    lastDownloadedAt.value = downloadedAt
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

  async function syncPending(): Promise<void> {
    const userId = auth.user?.id
    if (!userId || !online.value || syncing.value) return
    syncing.value = true
    lastSyncError.value = null
    try {
      const result = await fieldModeService.syncOutbox(userId)
      if (result.failed > 0) {
        lastSyncError.value = 'Algunas calificaciones no se pudieron sincronizar.'
      }
      await refreshMeta()
    } catch (error) {
      lastSyncError.value = error instanceof Error ? error.message : 'Error al sincronizar'
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
    lastDownloadedAt,
    lastSyncError,
    canUseFieldMode,
    hasPending,
    init,
    refreshMeta,
    downloadPack,
    cachedEvents,
    getJudgeBoard,
    saveCalificacion,
    syncPending,
  }
})
