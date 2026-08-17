export type BrandAssetKey = 'login_hero' | 'pattern_light' | 'pattern_dark'

export type ClubLoaderKey = 'conquistadores' | 'aventureros' | 'guias_mayores' | 'neutral'

export type LogoAnimation = 'float' | 'pulse' | 'spin' | 'bounce' | 'none'

export type RingAnimation = 'spin' | 'pulse' | 'none'

export type LoaderSpeed = 'slow' | 'normal' | 'fast'

export interface LoaderPreset {
  key: ClubLoaderKey
  logo_url: string | null
  ring_top: string
  ring_right: string
  glow: string
  label_color: string
  logo_animation: LogoAnimation
  ring_animation: RingAnimation
  speed: LoaderSpeed
}

import type { LoginHeroFit } from '@/modules/settings/loginHeroFit'
import type { LoginHeroCopy } from '@/modules/settings/loginHeroCopy'

export type { LoginHeroFit }
export type { LoginHeroCopy, LoginHeroDevice, LoginHeroVariant } from '@/modules/settings/loginHeroCopy'

export interface BrandSettings {
  login_hero_url: string | null
  login_hero_fit: LoginHeroFit
  login_hero_copy: LoginHeroCopy
  pattern_light_url: string | null
  pattern_dark_url: string | null
  loaders: Record<ClubLoaderKey, LoaderPreset>
  updated_at: string | null
}
