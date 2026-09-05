export const ICON_SIZE_MIN = 24
export const ICON_SIZE_MAX = 96
export const ICON_SIZE_DEFAULT = 48

export function clampIconSize(value: number | null | undefined): number {
  const size = Number(value)
  if (!Number.isFinite(size)) return ICON_SIZE_DEFAULT
  return Math.min(ICON_SIZE_MAX, Math.max(ICON_SIZE_MIN, Math.round(size)))
}

export function iconFontSize(value: number | null | undefined, max = ICON_SIZE_MAX): string {
  return `${Math.min(max, clampIconSize(value))}px`
}
