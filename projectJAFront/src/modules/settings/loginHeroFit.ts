export interface LoginHeroFit {
  x: number
  y: number
  zoom: number
}

export const DEFAULT_LOGIN_HERO_FIT: LoginHeroFit = {
  x: 50,
  y: 50,
  zoom: 1,
}

export const LOGIN_HERO_ZOOM_MIN = 1
export const LOGIN_HERO_ZOOM_MAX = 2.5

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

export function normalizeLoginHeroFit(fit?: Partial<LoginHeroFit> | null): LoginHeroFit {
  return {
    x: clamp(Number(Number(fit?.x ?? DEFAULT_LOGIN_HERO_FIT.x).toFixed(2)), 0, 100),
    y: clamp(Number(Number(fit?.y ?? DEFAULT_LOGIN_HERO_FIT.y).toFixed(2)), 0, 100),
    zoom: clamp(
      Number(Number(fit?.zoom ?? DEFAULT_LOGIN_HERO_FIT.zoom).toFixed(2)),
      LOGIN_HERO_ZOOM_MIN,
      LOGIN_HERO_ZOOM_MAX,
    ),
  }
}

export function loginHeroFitVars(fit: LoginHeroFit): Record<string, string> {
  const normalized = normalizeLoginHeroFit(fit)
  return {
    '--hero-x': `${normalized.x}%`,
    '--hero-y': `${normalized.y}%`,
    '--hero-zoom': String(normalized.zoom),
  }
}

export function isSameLoginHeroFit(a: LoginHeroFit, b: LoginHeroFit): boolean {
  return a.x === b.x && a.y === b.y && a.zoom === b.zoom
}
