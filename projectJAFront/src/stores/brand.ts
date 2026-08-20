import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { brandConfig } from '@/config/brand'
import { brandSettingsService } from '@/services/brandSettingsService'
import { useThemeStore } from '@/stores/theme'
import {
  DEFAULT_LOADER_PRESETS,
  LOADER_KEYS,
  mergeLoaderPreset,
  type ClubLoaderKey,
} from '@/modules/auth/clubLogin'
import type { BrandSettings, LoaderPreset } from '@/modules/settings/types'
import { resolveAssetUrl, toCssImageUrl } from '@/modules/settings/assetUrl'
import { DEFAULT_LOGIN_HERO_FIT, normalizeLoginHeroFit } from '@/modules/settings/loginHeroFit'
import { defaultLoginHeroCopy, normalizeLoginHeroCopy } from '@/modules/settings/loginHeroCopy'

function emptyLoaders(): Record<ClubLoaderKey, LoaderPreset> {
  return {
    neutral: { ...DEFAULT_LOADER_PRESETS.neutral },
    aventureros: { ...DEFAULT_LOADER_PRESETS.aventureros },
    conquistadores: { ...DEFAULT_LOADER_PRESETS.conquistadores },
    guias_mayores: { ...DEFAULT_LOADER_PRESETS.guias_mayores },
  }
}

const emptySettings = (): BrandSettings => ({
  login_hero_url: null,
  login_logos_url: null,
  login_hero_fit: { ...DEFAULT_LOGIN_HERO_FIT },
  login_hero_copy: defaultLoginHeroCopy(),
  pattern_light_url: null,
  pattern_dark_url: null,
  loaders: emptyLoaders(),
  updated_at: null,
})

function withCacheBust(url: string, stamp: string | null): string {
  const resolved = resolveAssetUrl(url) ?? url
  if (!stamp) return resolved
  const separator = resolved.includes('?') ? '&' : '?'
  return `${resolved}${separator}v=${encodeURIComponent(stamp)}`
}

function normalizeSettings(next: BrandSettings): BrandSettings {
  const loaders = emptyLoaders()
  for (const key of LOADER_KEYS) {
    loaders[key] = mergeLoaderPreset(key, next.loaders?.[key])
    if (loaders[key].logo_url) {
      loaders[key].logo_url = withCacheBust(
        loaders[key].logo_url,
        next.updated_at ?? String(Date.now()),
      )
    }
  }

  return {
    ...emptySettings(),
    ...next,
    login_hero_fit: normalizeLoginHeroFit(next.login_hero_fit),
    login_hero_copy: normalizeLoginHeroCopy(next.login_hero_copy),
    loaders,
  }
}

export const useBrandStore = defineStore('brand', () => {
  const theme = useThemeStore()
  const settings = ref<BrandSettings>(emptySettings())
  const loaded = ref(false)

  const loginHero = computed(() =>
    settings.value.login_hero_url
      ? withCacheBust(settings.value.login_hero_url, settings.value.updated_at)
      : brandConfig.loginHero,
  )

  const loginLogos = computed(() =>
    settings.value.login_logos_url
      ? withCacheBust(settings.value.login_logos_url, settings.value.updated_at)
      : brandConfig.logos,
  )

  const patternLight = computed(() =>
    settings.value.pattern_light_url
      ? withCacheBust(settings.value.pattern_light_url, settings.value.updated_at)
      : brandConfig.pattern,
  )

  const patternDark = computed(() =>
    settings.value.pattern_dark_url
      ? withCacheBust(settings.value.pattern_dark_url, settings.value.updated_at)
      : brandConfig.patternDark,
  )

  const pattern = computed(() => (theme.isDark ? patternDark.value : patternLight.value))

  const loginHeroCss = computed(() => toCssImageUrl(loginHero.value))
  const loginHeroFit = computed(() => normalizeLoginHeroFit(settings.value.login_hero_fit))
  const loginHeroCopy = computed(() => normalizeLoginHeroCopy(settings.value.login_hero_copy))
  const patternCss = computed(() =>
    toCssImageUrl(theme.isDark ? patternDark.value : patternLight.value),
  )

  function loaderPreset(key: ClubLoaderKey): LoaderPreset {
    return settings.value.loaders[key] ?? mergeLoaderPreset(key)
  }

  function apply(next: BrandSettings): void {
    settings.value = normalizeSettings(next)
    loaded.value = true
  }

  async function load(): Promise<void> {
    try {
      apply(await brandSettingsService.get())
    } catch {
      loaded.value = true
    }
  }

  return {
    settings,
    loaded,
    loginHero,
    loginLogos,
    loginHeroCss,
    loginHeroFit,
    loginHeroCopy,
    patternLight,
    patternDark,
    pattern,
    patternCss,
    loaderPreset,
    apply,
    load,
  }
})
