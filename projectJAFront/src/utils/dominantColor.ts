const API_ORIGIN = String(import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000').replace(/\/$/, '')

/**
 * Reescribe URLs de /storage al origen del front cuando hay proxy (Vite)
 * para poder leer píxeles sin CORS.
 */
export function toCanvasSafeUrl(url: string): string {
  try {
    const parsed = new URL(url, window.location.origin)
    if (!parsed.pathname.startsWith('/storage/')) {
      return url
    }

    const apiHost = new URL(API_ORIGIN).host
    const sameHost = parsed.host === window.location.host
    const localFront = ['localhost', '127.0.0.1'].includes(window.location.hostname)
    if (sameHost || (localFront && parsed.host === apiHost)) {
      return `${parsed.pathname}${parsed.search}`
    }
  } catch {
    return url
  }

  return url
}

function saturation(r: number, g: number, b: number): number {
  const max = Math.max(r, g, b) / 255
  const min = Math.min(r, g, b) / 255
  const delta = max - min
  if (max === 0) return 0
  return delta / max
}

function lightness(r: number, g: number, b: number): number {
  return (Math.max(r, g, b) + Math.min(r, g, b)) / 510
}

/**
 * Color que más ocupa (y más se ve) en la imagen, priorizando el lado
 * derecho porque es el que conecta con el panel de login.
 */
export function extractProminentColor(image: HTMLImageElement): string | null {
  const canvas = document.createElement('canvas')
  const size = 48
  canvas.width = size
  canvas.height = size
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) return null

  ctx.drawImage(image, 0, 0, size, size)

  let pixels: ImageData
  try {
    pixels = ctx.getImageData(0, 0, size, size)
  } catch {
    return null
  }

  const buckets = new Map<string, { r: number; g: number; b: number; count: number; score: number }>()

  for (let y = 0; y < size; y += 1) {
    for (let x = 0; x < size; x += 1) {
      const i = (y * size + x) * 4
      const alpha = pixels.data[i + 3]
      if (alpha < 200) continue

      const r = pixels.data[i]
      const g = pixels.data[i + 1]
      const b = pixels.data[i + 2]
      const sat = saturation(r, g, b)
      const lit = lightness(r, g, b)
      if (lit < 0.04 || lit > 0.96) continue

      const edgeWeight = 0.55 + (x / (size - 1)) * 0.9
      const chromaWeight = 0.45 + sat * 0.55
      const midTone = 1 - Math.abs(lit - 0.42) * 0.55
      const score = edgeWeight * chromaWeight * midTone

      const key = `${r >> 4}-${g >> 4}-${b >> 4}`
      const current = buckets.get(key)
      if (current) {
        current.r += r
        current.g += g
        current.b += b
        current.count += 1
        current.score += score
      } else {
        buckets.set(key, { r, g, b, count: 1, score })
      }
    }
  }

  let best: { r: number; g: number; b: number; count: number; score: number } | null = null
  for (const bucket of buckets.values()) {
    if (!best || bucket.score > best.score) {
      best = bucket
    }
  }

  if (!best || best.score <= 0) return null

  const r = Math.round(best.r / best.count)
  const g = Math.round(best.g / best.count)
  const b = Math.round(best.b / best.count)

  return `rgb(${r}, ${g}, ${b})`
}
