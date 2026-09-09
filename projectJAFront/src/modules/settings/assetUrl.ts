const API_ORIGIN = String(import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/$/, '')

export function resolveAssetUrl(url?: string | null): string | null {
  if (!url) {
    return null
  }

  if (url.startsWith('blob:') || url.startsWith('data:') || url.startsWith('/src/') || url.startsWith('/assets/')) {
    return url
  }

  try {
    const path = /^https?:\/\//i.test(url) ? new URL(url).pathname : url
    const normalized = path.replace(/\\/g, '/')
    for (const prefix of ['/api/v1/files/', '/storage/', 'api/v1/files/', 'storage/']) {
      if (!normalized.startsWith(prefix)) continue
      const rest = normalized.slice(prefix.length).replace(/^\/+/, '')
      if (rest && !rest.includes('..')) {
        return `${API_ORIGIN}/api/v1/files/${rest}`
      }
    }
  } catch {
    /* seguir con el resto de reglas */
  }

  if (url.startsWith('/')) {
    return `${API_ORIGIN}${url}`
  }

  if (!/^https?:\/\//i.test(url) && !url.includes('..')) {
    return `${API_ORIGIN}/api/v1/files/${url}`
  }

  try {
    const parsed = new URL(url)
    if (parsed.pathname.startsWith('/api/v1/files/')) {
      return `${API_ORIGIN}${parsed.pathname}${parsed.search}`
    }
    return url
  } catch {
    return url
  }
}

export function toCssImageUrl(url: string): string {
  return `url("${url.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}")`
}
