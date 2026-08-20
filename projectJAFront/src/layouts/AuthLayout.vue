<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useThemeStore } from '@/stores/theme'
import Button from 'primevue/button'
import { brandConfig } from '@/config/brand'
import { toCssImageUrl } from '@/modules/settings/assetUrl'
import { loginHeroFitVars } from '@/modules/settings/loginHeroFit'
import { useBrandStore } from '@/stores/brand'
import PwaInstallButton from '@/components/pwa/PwaInstallButton.vue'
import { extractProminentColor, toCanvasSafeUrl } from '@/utils/dominantColor'

const { t } = useI18n()
const route = useRoute()
const theme = useThemeStore()
const brand = useBrandStore()
const heroBroken = ref(false)
const heroImg = ref<HTMLImageElement | null>(null)
const panelBg = ref<string | null>(null)
const isDesktop = ref(window.matchMedia('(min-width: 960px)').matches)
let media: MediaQueryList | null = null

function syncViewport(): void {
  isDesktop.value = Boolean(media?.matches)
}

onMounted(() => {
  media = window.matchMedia('(min-width: 960px)')
  syncViewport()
  media.addEventListener('change', syncViewport)
  if (heroImg.value?.complete) {
    syncPanelColor()
  }
})

onBeforeUnmount(() => {
  media?.removeEventListener('change', syncViewport)
})

watch(() => brand.loginHero, () => {
  heroBroken.value = false
  panelBg.value = null
})

const heroUrl = computed(() => (heroBroken.value ? brandConfig.loginHero : brand.loginHero))
const heroSafeUrl = computed(() => toCanvasSafeUrl(heroUrl.value))
const heroCss = computed(() => toCssImageUrl(heroSafeUrl.value))
const heroCopy = computed(() =>
  isDesktop.value ? brand.loginHeroCopy.desktop : brand.loginHeroCopy.mobile,
)
const heroStyle = computed(() => ({
  backgroundImage: heroCss.value,
  backgroundColor: panelBg.value || undefined,
  ...loginHeroFitVars(heroCopy.value.fit),
}))
const shellStyle = computed(() => ({
  '--login-panel-bg': panelBg.value || 'var(--pj-bg)',
}))
const panelStyle = computed(() => ({
  '--pj-pattern': brand.patternCss,
}))

function syncPanelColor(): void {
  const image = heroImg.value
  if (!image || !image.naturalWidth) return
  panelBg.value = extractProminentColor(image)
}
</script>

<template>
  <div
    class="login-shell"
    :class="{ 'login-shell--wide': route.meta.wide }"
    :style="shellStyle"
  >
    <section
      class="login-hero"
      aria-label="ProjectJA"
      :style="heroStyle"
    >
      <img
        ref="heroImg"
        class="login-hero__image"
        :key="heroSafeUrl"
        :src="heroSafeUrl"
        alt=""
        decoding="async"
        @load="syncPanelColor"
        @error="heroBroken = true"
      />
      <div class="login-hero__overlay" />

      <div class="login-hero__content">
        <div class="login-hero__copy">
          <h1 class="login-hero__title">
            <span>{{ heroCopy.line1 }}</span>
            <em>{{ heroCopy.line2 }}</em>
          </h1>
          <p class="login-hero__subtitle">{{ heroCopy.subtitle }}</p>
        </div>

        <ul class="login-hero__features">
          <li v-for="(item, index) in heroCopy.features" :key="`${item.title}-${index}`">
            <span class="feature-icon" aria-hidden="true">
              <i :class="item.icon" />
            </span>
            <div>
              <strong>{{ item.title }}</strong>
              <p>{{ item.desc }}</p>
            </div>
          </li>
        </ul>
      </div>

      <!-- Curva vertical (desktop) -->
      <svg
        class="login-hero__wave login-hero__wave--side"
        viewBox="0 0 120 1000"
        preserveAspectRatio="none"
        aria-hidden="true"
      >
        <path
          d="M70 0
             C95 80 35 160 55 250
             C80 360 25 450 50 560
             C78 680 30 780 58 880
             C72 940 55 980 62 1000
             L120 1000 L120 0 Z"
          fill="currentColor"
        />
      </svg>

      <!-- Curva inferior (móvil) -->
      <svg
        class="login-hero__wave login-hero__wave--bottom"
        viewBox="0 0 1440 120"
        preserveAspectRatio="none"
        aria-hidden="true"
      >
        <path
          d="M0 40
             C180 110 360 0 540 45
             C720 95 900 10 1080 55
             C1260 100 1380 30 1440 50
             L1440 120 L0 120 Z"
          fill="currentColor"
        />
      </svg>
    </section>

    <section
      class="login-panel"
      :style="panelStyle"
    >
      <Button
        class="login-panel__theme"
        text
        rounded
        :icon="theme.isDark ? 'pi pi-sun' : 'pi pi-moon'"
        :aria-label="theme.isDark ? t('nav.themeLight') : t('nav.themeDark')"
        @click="theme.toggle()"
      />
      <div class="login-panel__card" :class="{ 'login-panel__card--wide': route.meta.wide }">
        <RouterView />
      </div>
      <PwaInstallButton class="login-panel__pwa" />
    </section>
  </div>
</template>

<style scoped>
.login-shell {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr;
  background: var(--login-panel-bg, var(--pj-bg));
}

.login-hero {
  position: relative;
  min-height: 46vh;
  overflow: hidden;
  color: #fff;
  isolation: isolate;
  background-color: #07122a;
  background-size: cover;
  background-position: var(--hero-x, 50%) var(--hero-y, 50%);
  background-repeat: no-repeat;
}

.login-hero__image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: var(--hero-x, 50%) var(--hero-y, 50%);
  transform: scale(var(--hero-zoom, 1));
  transform-origin: var(--hero-x, 50%) var(--hero-y, 50%);
  z-index: 0;
}

.login-hero__overlay {
  position: absolute;
  inset: 0;
  z-index: 1;
  background:
    linear-gradient(180deg, rgba(7, 18, 42, 0.18) 0%, rgba(7, 18, 42, 0.42) 55%, rgba(7, 18, 42, 0.72) 100%),
    radial-gradient(circle at 18% 18%, rgba(245, 197, 24, 0.12), transparent 42%);
}

.login-hero__content {
  position: relative;
  z-index: 2;
  height: 100%;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 1.5rem;
  padding: 1.5rem 1.25rem 2.75rem;
  max-width: 760px;
}

.login-hero__title {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  font-family: 'Sora', var(--pj-font-display), sans-serif;
  font-weight: 800;
  font-size: clamp(1.85rem, 4.5vw, 3rem);
  line-height: 1.05;
}

.login-hero__title em {
  font-family: 'Dancing Script', cursive;
  font-style: normal;
  font-weight: 700;
  color: var(--pj-gold);
  font-size: 1.32em;
  line-height: 1;
}

.login-hero__subtitle {
  margin: 0.9rem 0 0;
  max-width: 34rem;
  color: rgba(255, 255, 255, 0.9);
  font-size: 0.96rem;
  line-height: 1.55;
}

.login-hero__features {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.5rem;
}

.login-hero__features li {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.feature-icon {
  width: 2.45rem;
  height: 2.45rem;
  border-radius: 999px;
  border: 1.5px solid var(--pj-gold);
  display: grid;
  place-items: center;
  color: var(--pj-gold);
  flex-shrink: 0;
}

.login-hero__features strong {
  display: block;
  color: var(--pj-gold);
  font-size: 0.9rem;
  margin-bottom: 0.2rem;
}

.login-hero__features p {
  margin: 0;
  color: rgba(255, 255, 255, 0.82);
  font-size: 0.82rem;
  line-height: 1.4;
}

.login-hero__wave {
  position: absolute;
  z-index: 3;
  color: var(--login-panel-bg, var(--pj-bg));
  pointer-events: none;
}

@media (max-width: 959px) {
  .login-hero {
    min-height: 58vh;
  }

  .feature-icon {
    width: 1.85rem;
    height: 1.85rem;
  }

  .login-hero__features strong {
    font-size: 0.8rem;
  }

  .login-hero__features p {
    font-size: 0.74rem;
  }
}

.login-hero__wave--side {
  display: none;
}

.login-hero__wave--bottom {
  left: 0;
  right: 0;
  bottom: -1px;
  width: 100%;
  height: 72px;
}

.login-panel {
  position: relative;
  display: grid;
  justify-items: center;
  align-content: center;
  gap: 0.85rem;
  padding: 1.25rem max(1.25rem, env(safe-area-inset-right, 0px))
    max(1.25rem, env(safe-area-inset-bottom, 0px)) max(1.25rem, env(safe-area-inset-left, 0px));
  background-color: var(--login-panel-bg, var(--pj-bg));
  background-image: var(--pj-pattern);
  background-repeat: repeat;
  background-size: 420px auto;
  background-position: center top;
}

.login-panel__theme {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  color: var(--pj-text) !important;
}

.login-panel__pwa {
  width: min(100%, 420px);
}

.login-panel__card {
  width: min(100%, 420px);
  background: var(--pj-bg-elevated);
  color: var(--pj-text);
  border-radius: 1.25rem;
  box-shadow: 0 18px 50px rgba(11, 31, 74, 0.12);
  padding: 1.5rem 1.25rem 1.35rem;
}

.login-panel__card--wide {
  width: min(100%, 720px);
}

.login-shell--wide .login-panel__pwa {
  width: min(100%, 720px);
}

@media (min-width: 960px) {
  .login-shell {
    grid-template-columns: minmax(0, 1.2fr) minmax(360px, 0.8fr);
  }

  .login-shell--wide {
    grid-template-columns: minmax(0, 0.85fr) minmax(560px, 1.15fr);
  }

  .login-hero {
    min-height: 100vh;
  }

  .login-hero__content {
    justify-content: space-between;
    padding: 4.5rem 5.5rem 2.5rem 2.75rem;
    max-width: none;
    height: 100%;
  }

  .login-hero__copy {
    margin-top: clamp(3rem, 12vh, 8rem);
  }

  .login-hero__features {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .login-hero__wave--bottom {
    display: none;
  }

  .login-hero__wave--side {
    display: block;
    top: 0;
    right: -1px;
    width: clamp(72px, 8vw, 120px);
    height: 100%;
  }

  .login-panel__pwa {
    display: none;
  }

  .login-panel {
    padding: 2rem 2rem 2rem 1rem;
  }

  .login-panel__card {
    padding: 2rem 1.75rem;
  }
}
</style>
