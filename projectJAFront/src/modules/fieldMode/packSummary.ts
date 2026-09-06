import type { FieldEventPack } from '@/modules/fieldMode/types'

export interface FieldSubeventoSummary {
  subeventoId: number
  actividadId: number
  nombre: string
  clubes: number
  clubesPendientes: number
}

export interface FieldEventSummary {
  eventId: number
  eventName: string
  imageUrl?: string | null
  downloadedAt: string | null
  subeventos: FieldSubeventoSummary[]
  clubes: number
  clubesPendientes: number
}

function pendingClubsForActivity(eventPack: FieldEventPack, actividadId: number, subeventoId: number): number {
  const pendientes = eventPack.board.pendientes ?? {}
  let count = 0
  for (const byEvento of Object.values(pendientes)) {
    const raw = byEvento[String(actividadId)] ?? byEvento[String(subeventoId)] ?? 0
    if (Number(raw) > 0) count += 1
  }
  return count
}

export function summarizeEventPack(
  eventPack: FieldEventPack,
  packDownloadedAt?: string | null,
): FieldEventSummary {
  const seenClubs = new Set<number>()
  const subeventos = eventPack.activities.map((slice) => {
    for (const club of slice.clubes) {
      seenClubs.add(club.organizacion_id)
    }
    const fromBoard = pendingClubsForActivity(eventPack, slice.actividad_id, slice.subevento_id)
    const fromClubes = slice.clubes.filter((club) => club.estado === 'pendiente').length
    return {
      subeventoId: slice.subevento_id,
      actividadId: slice.actividad_id,
      nombre: slice.actividad?.name || slice.subevento?.name || `#${slice.actividad_id}`,
      clubes: slice.clubes.length,
      clubesPendientes: fromBoard || fromClubes,
    }
  })

  return {
    eventId: eventPack.event.id,
    eventName: eventPack.event.name,
    imageUrl: eventPack.event.image_url || eventPack.event.banner_url || null,
    downloadedAt: eventPack.downloaded_at || packDownloadedAt || null,
    subeventos,
    clubes: seenClubs.size,
    clubesPendientes: subeventos.reduce((sum, item) => sum + item.clubesPendientes, 0),
  }
}
