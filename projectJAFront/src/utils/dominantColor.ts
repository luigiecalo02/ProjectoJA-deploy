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
export function extractProminentColor(
  image: HTMLImageElement,
  options?: { uniform?: boolean },
): string | null {
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

      const edgeWeight = options?.uniform ? 1 : 0.55 + (x / (size - 1)) * 0.9
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

export type BannerHeroVars = Record<string, string>

type Rgb = { r: number; g: number; b: number; luma: number }

function clampByte(value: number): number {
  return Math.max(0, Math.min(255, Math.round(value)))
}

function relativeLuma(r: number, g: number, b: number): number {
  const toLin = (value: number) => {
    const s = value / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * toLin(r) + 0.7152 * toLin(g) + 0.0722 * toLin(b)
}

function parseRgb(css: string | null): Rgb | null {
  if (!css) return null
  const match = css.match(/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/)
  if (!match) return null
  const r = Number(match[1])
  const g = Number(match[2])
  const b = Number(match[3])
  return { r, g, b, luma: relativeLuma(r, g, b) }
}

function mixRgb(from: Rgb, to: Rgb, amount: number): Rgb {
  const r = clampByte(from.r + (to.r - from.r) * amount)
  const g = clampByte(from.g + (to.g - from.g) * amount)
  const b = clampByte(from.b + (to.b - from.b) * amount)
  return { r, g, b, luma: relativeLuma(r, g, b) }
}

function rgba(color: Rgb, alpha: number): string {
  return `rgba(${color.r}, ${color.g}, ${color.b}, ${alpha})`
}

function loadCanvasImage(url: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image()
    image.decoding = 'async'
    image.onload = () => resolve(image)
    image.onerror = () => reject(new Error('No se pudo leer el banner'))
    image.src = toCanvasSafeUrl(url)
  })
}

function sampleRegion(
  image: HTMLImageElement,
  region: { x0: number; x1: number; y0: number; y1: number },
): Rgb {
  const canvas = document.createElement('canvas')
  const width = 48
  const height = 24
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d', { willReadFrequently: true })
  if (!ctx) return { r: 16, g: 24, b: 40, luma: 0.12 }

  ctx.drawImage(image, 0, 0, width, height)
  let pixels: ImageData
  try {
    pixels = ctx.getImageData(0, 0, width, height)
  } catch {
    return { r: 16, g: 24, b: 40, luma: 0.12 }
  }

  let r = 0
  let g = 0
  let b = 0
  let count = 0
  const xStart = Math.floor(region.x0 * width)
  const xEnd = Math.ceil(region.x1 * width)
  const yStart = Math.floor(region.y0 * height)
  const yEnd = Math.ceil(region.y1 * height)

  for (let y = yStart; y < yEnd; y += 1) {
    for (let x = xStart; x < xEnd; x += 1) {
      const i = (y * width + x) * 4
      if (pixels.data[i + 3] < 180) continue
      r += pixels.data[i]
      g += pixels.data[i + 1]
      b += pixels.data[i + 2]
      count += 1
    }
  }

  if (!count) return { r: 16, g: 24, b: 40, luma: 0.12 }
  const avg = {
    r: Math.round(r / count),
    g: Math.round(g / count),
    b: Math.round(b / count),
  }
  return { ...avg, luma: relativeLuma(avg.r, avg.g, avg.b) }
}

/**
 * Variables CSS para un encabezado sobre banner: texto, chips y velo
 * adaptados a los tonos claros/oscuros de la imagen.
 */
export async function extractBannerHeroVars(imageUrl: string): Promise<BannerHeroVars> {
  const image = await loadCanvasImage(imageUrl)
  const tone =
    parseRgb(extractProminentColor(image, { uniform: true }))
    ?? sampleRegion(image, { x0: 0, x1: 1, y0: 0.2, y1: 0.8 })
  const left = sampleRegion(image, { x0: 0, x1: 0.42, y0: 0.22, y1: 0.78 })
  const right = sampleRegion(image, { x0: 0.58, x1: 1, y0: 0.22, y1: 0.78 })

  const leftDark = left.luma < 0.52
  const rightDark = right.luma < 0.52
  const ink = { r: 16, g: 32, b: 51, luma: 0.12 }
  const paper = { r: 255, g: 255, b: 255, luma: 1 }
  const text = leftDark ? paper : ink
  const chipText = rightDark ? paper : ink
  const overlayDeep = mixRgb(tone, ink, leftDark ? 0.62 : 0.18)
  const overlaySoft = mixRgb(tone, leftDark ? ink : paper, leftDark ? 0.35 : 0.42)
  const chipBase = mixRgb(right, rightDark ? ink : paper, rightDark ? 0.48 : 0.62)

  return {
    '--hero-overlay': `linear-gradient(180deg, ${rgba(overlaySoft, leftDark ? 0.28 : 0.38)} 0%, ${rgba(overlayDeep, leftDark ? 0.78 : 0.7)} 100%)`,
    '--hero-text': `rgb(${text.r}, ${text.g}, ${text.b})`,
    '--hero-muted': rgba(text, leftDark ? 0.84 : 0.72),
    '--hero-chip-bg': rgba(chipBase, rightDark ? 0.52 : 0.78),
    '--hero-chip-border': rgba(chipText, 0.28),
    '--hero-chip-text': `rgb(${chipText.r}, ${chipText.g}, ${chipText.b})`,
    '--hero-chip-muted': rgba(chipText, rightDark ? 0.78 : 0.68),
    '--hero-btn-bg': rgba(chipBase, rightDark ? 0.36 : 0.7),
    '--hero-btn-border': rgba(chipText, 0.38),
    '--hero-btn-text': `rgb(${chipText.r}, ${chipText.g}, ${chipText.b})`,
    '--hero-tone': `rgb(${tone.r}, ${tone.g}, ${tone.b})`,
  }
}
