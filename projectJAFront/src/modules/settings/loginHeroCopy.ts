import {
  DEFAULT_LOGIN_HERO_FIT,
  normalizeLoginHeroFit,
  type LoginHeroFit,
} from '@/modules/settings/loginHeroFit'

export type LoginHeroDevice = 'desktop' | 'mobile'

export interface LoginHeroFeature {
  icon: string
  title: string
  desc: string
}

export interface LoginHeroVariant {
  line1: string
  line2: string
  subtitle: string
  features: LoginHeroFeature[]
  fit: LoginHeroFit
}

export interface LoginHeroCopy {
  desktop: LoginHeroVariant
  mobile: LoginHeroVariant
}

export const LOGIN_HERO_ICONS = [
  'pi pi-users',
  'pi pi-shield',
  'pi pi-heart',
  'pi pi-flag',
  'pi pi-star',
  'pi pi-book',
  'pi pi-globe',
  'pi pi-home',
  'pi pi-map',
  'pi pi-compass',
  'pi pi-building',
  'pi pi-calendar',
  'pi pi-check-circle',
  'pi pi-sun',
  'pi pi-bolt',
  'pi pi-comments',
  'pi pi-verified',
  'pi pi-trophy',
  'pi pi-sparkles',
  'pi pi-sitemap',
] as const

const DEFAULT_FEATURES: LoginHeroFeature[] = [
  {
    icon: 'pi pi-users',
    title: 'Gestión de Clubes',
    desc: 'Organiza y administra tus clubes de forma eficiente.',
  },
  {
    icon: 'pi pi-shield',
    title: 'Crecimiento Espiritual',
    desc: 'Herramientas para el desarrollo espiritual y personal.',
  },
  {
    icon: 'pi pi-heart',
    title: 'Servicio y Amistad',
    desc: 'Juntos para hacer la diferencia en nuestra comunidad.',
  },
]

function defaultVariant(): LoginHeroVariant {
  return {
    line1: 'Unidos para',
    line2: 'Servir y Salvar',
    subtitle:
      'Plataforma oficial para la gestión de clubes de Conquistadores, Aventureros y Jóvenes Adventistas.',
    features: DEFAULT_FEATURES.map((item) => ({ ...item })),
    fit: { ...DEFAULT_LOGIN_HERO_FIT },
  }
}

export function defaultLoginHeroCopy(): LoginHeroCopy {
  return {
    desktop: defaultVariant(),
    mobile: defaultVariant(),
  }
}

function normalizeFeature(item?: Partial<LoginHeroFeature> | null, index = 0): LoginHeroFeature {
  const fallback = DEFAULT_FEATURES[index] ?? DEFAULT_FEATURES[0]
  const icon = String(item?.icon ?? fallback.icon)
  return {
    icon: (LOGIN_HERO_ICONS as readonly string[]).includes(icon) ? icon : fallback.icon,
    title: String(item?.title ?? fallback.title).slice(0, 60),
    desc: String(item?.desc ?? fallback.desc).slice(0, 160),
  }
}

export function normalizeLoginHeroVariant(value?: Partial<LoginHeroVariant> | null): LoginHeroVariant {
  const defaults = defaultVariant()
  const features = Array.isArray(value?.features) ? value.features : []
  return {
    line1: String(value?.line1 ?? defaults.line1).slice(0, 80),
    line2: String(value?.line2 ?? defaults.line2).slice(0, 80),
    subtitle: String(value?.subtitle ?? defaults.subtitle).slice(0, 240),
    features: [0, 1, 2].map((index) => normalizeFeature(features[index], index)),
    fit: normalizeLoginHeroFit(value?.fit),
  }
}

export function normalizeLoginHeroCopy(value?: Partial<LoginHeroCopy> | null): LoginHeroCopy {
  return {
    desktop: normalizeLoginHeroVariant(value?.desktop),
    mobile: normalizeLoginHeroVariant(value?.mobile),
  }
}
