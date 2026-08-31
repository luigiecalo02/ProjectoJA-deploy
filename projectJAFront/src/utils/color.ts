export const DEFAULT_COLOR_HEX = '#2563eb'
export const DEFAULT_COLOR_ALPHA = 1
export const DEFAULT_ESTRUCTURA_HEX = '#6d4c41'
export const DEFAULT_ESTRUCTURA_ALPHA = 0.45
export const DEFAULT_ZONA_HEX = '#00897b'
export const DEFAULT_ZONA_ALPHA = 0.35

export type ParsedMapColor = {
  hex: string
  alpha: number
}

function expandHex3(value: string): string {
  const h = value.slice(1)
  return `#${h[0]}${h[0]}${h[1]}${h[1]}${h[2]}${h[2]}`
}

export function parseMapColor(
  value?: string | null,
  fallbackHex = DEFAULT_COLOR_HEX,
  fallbackAlpha = DEFAULT_COLOR_ALPHA,
): ParsedMapColor {
  const raw = (value ?? '').trim().toLowerCase()
  if (/^#[0-9a-f]{3}$/.test(raw)) {
    return { hex: expandHex3(raw), alpha: fallbackAlpha }
  }
  if (/^#[0-9a-f]{6}$/.test(raw)) {
    return { hex: raw, alpha: fallbackAlpha }
  }
  if (/^#[0-9a-f]{8}$/.test(raw)) {
    return {
      hex: raw.slice(0, 7),
      alpha: Math.round((parseInt(raw.slice(7, 9), 16) / 255) * 100) / 100,
    }
  }
  return { hex: fallbackHex, alpha: fallbackAlpha }
}

export function serializeMapColor(
  hex: string,
  alpha: number,
  fallbackHex = DEFAULT_COLOR_HEX,
): string {
  const { hex: safeHex } = parseMapColor(hex, fallbackHex, 1)
  const clamped = Math.max(0.08, Math.min(1, alpha))
  const aa = Math.round(clamped * 255)
    .toString(16)
    .padStart(2, '0')
  return `${safeHex}${aa}`
}

export function cssColor(value?: string | null): string | undefined {
  if (!value?.trim()) return undefined
  return colorToCss(value)
}

export function colorToCss(
  value?: string | null,
  fallbackHex = DEFAULT_COLOR_HEX,
  fallbackAlpha = DEFAULT_COLOR_ALPHA,
): string {
  const { hex, alpha } = parseMapColor(value, fallbackHex, fallbackAlpha)
  const r = Number.parseInt(hex.slice(1, 3), 16)
  const g = Number.parseInt(hex.slice(3, 5), 16)
  const b = Number.parseInt(hex.slice(5, 7), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}
