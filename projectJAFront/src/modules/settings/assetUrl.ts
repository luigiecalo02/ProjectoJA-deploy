const API_ORIGIN = String(import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/$/, '')

export function resolveAssetUrl(url?: string | null): string | null {
  if (!url) {
    return null
  }

  if (url.startsWith('blob:') || url.startsWith('data:') || url.startsWith('/src/') || url.startsWith('/assets/')) {
    return url
  }

  if (url.startsWith('/')) {
    return `${API_ORIGIN}${url}`
  }

  try {
    const parsed = new URL(url)
    if (parsed.pathname.startsWith('/storage/')) {
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
