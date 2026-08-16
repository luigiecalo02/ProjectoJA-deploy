import defaultHero from '@/assets/brand/login-hero.jpg'
import clubLogos from '@/assets/brand/clubes-logos.png'
import patternScout from '@/assets/brand/pattern-scout.png'
import patternDark from '@/assets/brand/pattern-dark.png'
import directivaHero from '@/assets/brand/directiva-hero.png'
import appLogo from '@/assets/brand/app-logo.png'
import appIcon from '@/assets/brand/app-icon.png'
import conquistadoresLogo from '@/assets/brand/conquistadores-club.png'

/**
 * Paleta basada en logos JA / Conquistadores / Aventureros / Guías.
 * - appIcon: emblema circular (favicon, PWA, sidebar)
 * - appLogo: emblema completo con texto (login / marca amplia)
 */
export const brandConfig = {
  appIcon,
  appLogo,
  conquistadoresLogo,
  logos: clubLogos,
  loginHero: (import.meta.env.VITE_LOGIN_HERO_URL as string | undefined)?.trim() || defaultHero,
  pattern: patternScout,
  patternDark,
  directivaHero,
  primary: '#0B2F6B',
  primaryDark: '#071E48',
  accent: '#FFCC00',
  accentSoft: '#F5C518',
  danger: '#ED1C24',
  success: '#39B54A',
  sky: '#00AEEF',
}
