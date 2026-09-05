import { resolveFileUrl } from '@/services/api'

export function isImageIcon(value?: string | null): boolean {
  const raw = (value ?? '').trim()
  if (!raw || raw.startsWith('pi ')) return false
  return (
    /^https?:\/\//i.test(raw) ||
    raw.startsWith('/') ||
    raw.startsWith('iconos/') ||
    raw.startsWith('data:') ||
    /\.(gif|png|jpe?g|webp|svg)(\?|$)/i.test(raw)
  )
}

export function iconImageSrc(value?: string | null): string | null {
  if (!isImageIcon(value)) return null
  return resolveFileUrl(value)
}
