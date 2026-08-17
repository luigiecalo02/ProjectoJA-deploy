import {
  TIPO_AVENTUREROS,
  TIPO_CLUB,
  TIPO_CONQUISTADORES,
  TIPO_GUIAS_MAYORES,
} from '@/modules/organizaciones/types'
import type { AuthContextOption } from '@/modules/auth/types'
import conquistadoresLogo from '@/assets/brand/conquistadores-club.png'
import aventurerosLogo from '@/assets/brand/aventureros-club.png'
import guiasLogo from '@/assets/brand/guias-club.png'
import neutralLogo from '@/assets/brand/neutral-loader.png'
import type {
  ClubLoaderKey,
  LoaderPreset,
  LoaderSpeed,
  LogoAnimation,
  RingAnimation,
} from '@/modules/settings/types'

export type { ClubLoaderKey, LoaderPreset, LoaderSpeed, LogoAnimation, RingAnimation }

export const CLUB_LOGIN_STORAGE_KEY = 'projectja_login_club'

export const LOADER_KEYS: ClubLoaderKey[] = [
  'neutral',
  'aventureros',
  'conquistadores',
  'guias_mayores',
]

export const DEFAULT_LOADER_LOGOS: Record<ClubLoaderKey, string> = {
  conquistadores: conquistadoresLogo,
  aventureros: aventurerosLogo,
  guias_mayores: guiasLogo,
  neutral: neutralLogo,
}

export const DEFAULT_LOADER_PRESETS: Record<ClubLoaderKey, LoaderPreset> = {
  conquistadores: {
    key: 'conquistadores',
    logo_url: null,
    ring_top: '#ffcc00',
    ring_right: '#ed1c24',
    glow: '#0b2f6b',
    label_color: '#0b2f6b',
    logo_animation: 'float',
    ring_animation: 'spin',
    speed: 'normal',
  },
  aventureros: {
    key: 'aventureros',
    logo_url: null,
    ring_top: '#00aeef',
    ring_right: '#0b2f6b',
    glow: '#00aeef',
    label_color: '#0b2f6b',
    logo_animation: 'float',
    ring_animation: 'spin',
    speed: 'normal',
  },
  guias_mayores: {
    key: 'guias_mayores',
    logo_url: null,
    ring_top: '#f5c518',
    ring_right: '#0b2f6b',
    glow: '#0b2f6b',
    label_color: '#0b2f6b',
    logo_animation: 'float',
    ring_animation: 'spin',
    speed: 'normal',
  },
  neutral: {
    key: 'neutral',
    logo_url: null,
    ring_top: '#0b2f6b',
    ring_right: '#f5c518',
    glow: '#0b2f6b',
    label_color: '#0b2f6b',
    logo_animation: 'float',
    ring_animation: 'spin',
    speed: 'normal',
  },
}

export const LOGO_ANIMATIONS: LogoAnimation[] = ['float', 'pulse', 'spin', 'bounce', 'none']
export const RING_ANIMATIONS: RingAnimation[] = ['spin', 'pulse', 'none']
export const LOADER_SPEEDS: LoaderSpeed[] = ['slow', 'normal', 'fast']

export function isClubLoaderKey(value: unknown): value is ClubLoaderKey {
  return LOADER_KEYS.includes(value as ClubLoaderKey)
}

export function clubLoaderKeyFromTipoId(tipoId?: number | null): ClubLoaderKey {
  if (tipoId === TIPO_CONQUISTADORES) return 'conquistadores'
  if (tipoId === TIPO_AVENTUREROS) return 'aventureros'
  if (tipoId === TIPO_GUIAS_MAYORES) return 'guias_mayores'
  return 'neutral'
}

export function clubLoaderKeyFromName(value?: string | null): ClubLoaderKey | null {
  if (!value) return null
  const normalized = value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()

  if (normalized.includes('aventurero')) return 'aventureros'
  if (normalized.includes('conquistador')) return 'conquistadores'
  if (normalized.includes('guia')) return 'guias_mayores'
  return null
}

export function clubLoaderKeyFromClubTipos(tipos?: string[] | null): ClubLoaderKey | null {
  if (!tipos?.length) return null
  for (const tipo of tipos) {
    if (isClubLoaderKey(tipo) && tipo !== 'neutral') {
      return tipo
    }
    const fromName = clubLoaderKeyFromName(tipo)
    if (fromName) return fromName
  }
  return null
}

export function clubLoaderKeyFromContext(option?: AuthContextOption | null): ClubLoaderKey {
  if (!option || option.is_platform) {
    return 'neutral'
  }

  const fromTipo = clubLoaderKeyFromTipoId(option.tipo_organizacion_id)
  if (fromTipo !== 'neutral') {
    return fromTipo
  }

  const fromClub = clubLoaderKeyFromClubTipos(option.club_tipos)
  if (fromClub) {
    return fromClub
  }

  const fromLabel =
    clubLoaderKeyFromName(option.tipo_nombre) ?? clubLoaderKeyFromName(option.organizacion_nombre)
  if (fromLabel) {
    return fromLabel
  }

  if (option.is_club || option.tipo_organizacion_id === TIPO_CLUB) {
    return 'conquistadores'
  }

  return 'neutral'
}

export function mergeLoaderPreset(
  key: ClubLoaderKey,
  override?: Partial<LoaderPreset> | null,
): LoaderPreset {
  return {
    ...DEFAULT_LOADER_PRESETS[key],
    ...override,
    key,
  }
}

export function persistClubLoader(key: ClubLoaderKey): void {
  try {
    localStorage.setItem(CLUB_LOGIN_STORAGE_KEY, key)
  } catch {
    // ignore quota / private mode
  }
}

export function readStoredClubLoader(): ClubLoaderKey | null {
  try {
    const stored = localStorage.getItem(CLUB_LOGIN_STORAGE_KEY)
    return isClubLoaderKey(stored) ? stored : null
  } catch {
    return null
  }
}
