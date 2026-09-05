import { colorToCss, parseMapColor } from '@/utils/color'
import { iconFontSize } from '@/utils/iconSize'

const NEUTRAL_HEX = '#1e3a5f'

export function resolveEventIconColor(item: {
  color?: string | null
  categoria_subevento?: { color?: string | null } | null
  tipo_evento?: { color?: string | null } | null
}): string | null {
  return item.color || item.categoria_subevento?.color || item.tipo_evento?.color || null
}

/** Glifo en color sólido; fondo rebajado para que el icono se lea. */
export function iconBoxStyle(
  color?: string | null,
  options: { size?: number | null; maxSize?: number } = {},
): Record<string, string> {
  const raw = (color ?? '').trim()
  const parsed = raw ? parseMapColor(raw) : { hex: NEUTRAL_HEX, alpha: 0.1 }
  const fillAlpha = raw ? Math.max(0.12, Math.min(0.22, parsed.alpha * 0.28)) : 0.1
  const fill = colorToCss(parsed.hex, parsed.hex, fillAlpha)
  const style: Record<string, string> = {
    color: parsed.hex,
    background: fill,
    backgroundColor: fill,
    borderColor: colorToCss(parsed.hex, parsed.hex, Math.max(0.22, Math.min(0.38, parsed.alpha * 0.4))),
  }
  if (options.size != null || options.maxSize != null) {
    style.fontSize = iconFontSize(options.size, options.maxSize ?? 96)
  }
  return style
}
