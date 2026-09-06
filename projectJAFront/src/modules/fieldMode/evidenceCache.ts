import { resolveAssetUrl } from '@/modules/settings/assetUrl'
import type { FieldOfflinePack } from '@/modules/fieldMode/types'

const CACHE_NAME = 'projectja-field-evidence'
const MAX_PDF_BYTES = 2 * 1024 * 1024
const blobUrlBySource = new Map<string, string>()

function isVideoOrAudio(url: string, tipo?: string | null): boolean {
  const kind = (tipo || '').toLowerCase()
  if (kind === 'video' || kind === 'audio') return true
  return /\.(mp4|webm|mov|m4v|avi|mkv|mp3|wav|ogg|m4a|aac|flac)(\?|$)/i.test(url)
}

function isPdf(url: string, tipo?: string | null): boolean {
  return (tipo || '').toLowerCase() === 'pdf' || /\.pdf(\?|$)/i.test(url)
}

function pushUrl(target: Set<string>, raw?: string | null, tipo?: string | null): void {
  const resolved = resolveAssetUrl(raw) ?? raw
  if (!resolved || resolved.startsWith('blob:') || resolved.startsWith('data:')) return
  if (isVideoOrAudio(resolved, tipo)) return
  target.add(resolved)
}

export function collectCacheableEvidenceUrls(pack: FieldOfflinePack): string[] {
  const urls = new Set<string>()
  for (const item of pack.events) {
    pushUrl(urls, item.event.image_url)
    pushUrl(urls, item.event.banner_url)
    pushUrl(urls, item.board.evento.image_url)
    pushUrl(urls, item.board.evento.banner_url)
    for (const club of item.board.clubes) {
      pushUrl(urls, club.logo_url)
      for (const evidence of club.evidencias ?? []) {
        pushUrl(urls, evidence.url, evidence.tipo)
        if (evidence.file?.path) {
          pushUrl(urls, `/storage/${evidence.file.path}`, evidence.tipo)
        }
      }
    }
    for (const slice of item.activities) {
      for (const club of slice.clubes) {
        pushUrl(urls, club.logo_url)
        for (const evidence of club.evidencias ?? []) {
          pushUrl(urls, evidence.url, evidence.tipo)
          if (evidence.file?.path) {
            pushUrl(urls, `/storage/${evidence.file.path}`, evidence.tipo)
          }
        }
      }
    }
  }
  return [...urls]
}

export async function precacheEvidenceUrls(urls: string[]): Promise<void> {
  if (!urls.length || typeof caches === 'undefined') return
  const cache = await caches.open(CACHE_NAME)
  await Promise.all(
    urls.map(async (url) => {
      try {
        const response = await fetch(url, { credentials: 'same-origin' })
        if (!response.ok) return
        const pdf = isPdf(url)
        const length = Number(response.headers.get('content-length') || 0)
        if (pdf && length > MAX_PDF_BYTES) return
        const blob = await response.blob()
        if (pdf && blob.size > MAX_PDF_BYTES) return
        await cache.put(url, new Response(blob, { headers: response.headers }))
      } catch {
        // Una evidencia que no baje no tumba el pack.
      }
    }),
  )
}

export async function resolveCachedEvidenceUrl(url: string): Promise<string> {
  const resolved = resolveAssetUrl(url) ?? url
  if (!resolved || resolved.startsWith('blob:') || resolved.startsWith('data:')) {
    return resolved
  }
  const existing = blobUrlBySource.get(resolved)
  if (existing) return existing
  if (typeof caches === 'undefined') return resolved
  try {
    const cache = await caches.open(CACHE_NAME)
    const match = await cache.match(resolved)
    if (!match) return resolved
    const blob = await match.blob()
    const objectUrl = URL.createObjectURL(blob)
    blobUrlBySource.set(resolved, objectUrl)
    return objectUrl
  } catch {
    return resolved
  }
}

export async function clearEvidenceCache(): Promise<void> {
  for (const objectUrl of blobUrlBySource.values()) {
    URL.revokeObjectURL(objectUrl)
  }
  blobUrlBySource.clear()
  if (typeof caches === 'undefined') return
  await caches.delete(CACHE_NAME).catch(() => undefined)
}
