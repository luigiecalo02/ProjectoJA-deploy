<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import PageLoader from '@/components/PageLoader.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import LoaderSettingsPanel from '@/components/settings/LoaderSettingsPanel.vue'
import LoginHeroStudio from '@/components/settings/LoginHeroStudio.vue'
import { usePermission } from '@/composables/usePermission'
import { getApiErrorMessage } from '@/services/api'
import { brandSettingsService } from '@/services/brandSettingsService'
import { useBrandStore } from '@/stores/brand'
import { toCssImageUrl } from '@/modules/settings/assetUrl'
import { loginHeroFitVars } from '@/modules/settings/loginHeroFit'
import type { BrandAssetKey, LoginHeroCopy } from '@/modules/settings/types'

interface BrandSlot {
  key: BrandAssetKey
  titleKey: string
  hintKey: string
  placementKey: string
  preview: 'cover' | 'contain' | 'tile'
}

const { t } = useI18n()
const toast = useToast()
const { can } = usePermission()
const brand = useBrandStore()

const loading = ref(true)
const uploading = ref<BrandAssetKey | null>(null)
const resetting = ref<BrandAssetKey | null>(null)
const savingFit = ref(false)
const fitDrawerOpen = ref(false)
const localPreviews = ref<Partial<Record<BrandAssetKey, string>>>({})

const canUpdate = computed(() => can('settings.update'))

const slots: BrandSlot[] = [
  {
    key: 'login_logos',
    titleKey: 'settings.loginLogos',
    hintKey: 'settings.loginLogosHint',
    placementKey: 'settings.loginLogosPlacement',
    preview: 'contain',
  },
  {
    key: 'login_hero',
    titleKey: 'settings.loginHero',
    hintKey: 'settings.loginHeroHint',
    placementKey: 'settings.loginHeroPlacement',
    preview: 'cover',
  },
  {
    key: 'pattern_light',
    titleKey: 'settings.patternLight',
    hintKey: 'settings.patternLightHint',
    placementKey: 'settings.patternLightPlacement',
    preview: 'tile',
  },
  {
    key: 'pattern_dark',
    titleKey: 'settings.patternDark',
    hintKey: 'settings.patternDarkHint',
    placementKey: 'settings.patternDarkPlacement',
    preview: 'tile',
  },
]

function previewUrl(slot: BrandSlot): string {
  if (localPreviews.value[slot.key]) {
    return localPreviews.value[slot.key] as string
  }
  if (slot.key === 'login_logos') return brand.loginLogos
  if (slot.key === 'login_hero') return brand.loginHero
  if (slot.key === 'pattern_light') return brand.patternLight
  return brand.patternDark
}

function isCustom(slot: BrandSlot): boolean {
  if (slot.key === 'login_logos') return Boolean(brand.settings.login_logos_url)
  if (slot.key === 'login_hero') return Boolean(brand.settings.login_hero_url)
  if (slot.key === 'pattern_light') return Boolean(brand.settings.pattern_light_url)
  return Boolean(brand.settings.pattern_dark_url)
}

function notifyError(error: unknown): void {
  toast.add({
    severity: 'error',
    summary: t('common.error'),
    detail: getApiErrorMessage(error),
    life: 4000,
  })
}

async function loadSettings(): Promise<void> {
  loading.value = true
  try {
    await brand.load()
  } catch (error) {
    notifyError(error)
  } finally {
    loading.value = false
  }
}

function revokePreview(key: BrandAssetKey): void {
  const previous = localPreviews.value[key]
  if (previous) URL.revokeObjectURL(previous)
}

async function onSelect(slot: BrandSlot, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !canUpdate.value) return

  revokePreview(slot.key)
  localPreviews.value = {
    ...localPreviews.value,
    [slot.key]: URL.createObjectURL(file),
  }

  uploading.value = slot.key
  try {
    brand.apply(await brandSettingsService.upload(slot.key, file))
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.uploadSuccess'),
      life: 2500,
    })
  } catch (error) {
    notifyError(error)
  } finally {
    uploading.value = null
  }
}

async function saveHeroCopy(copy: LoginHeroCopy): Promise<void> {
  if (!canUpdate.value) return
  savingFit.value = true
  try {
    brand.apply(await brandSettingsService.updateHeroCopy(copy))
    fitDrawerOpen.value = false
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.heroFitSaved'),
      life: 2500,
    })
  } catch (error) {
    notifyError(error)
  } finally {
    savingFit.value = false
  }
}

async function resetSlot(slot: BrandSlot): Promise<void> {
  if (!canUpdate.value) return
  resetting.value = slot.key
  try {
    revokePreview(slot.key)
    const next = { ...localPreviews.value }
    delete next[slot.key]
    localPreviews.value = next
    brand.apply(await brandSettingsService.reset(slot.key))
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.resetSuccess'),
      life: 2500,
    })
  } catch (error) {
    notifyError(error)
  } finally {
    resetting.value = null
  }
}

onMounted(() => {
  void loadSettings()
})

onBeforeUnmount(() => {
  for (const key of Object.keys(localPreviews.value) as BrandAssetKey[]) {
    revokePreview(key)
  }
})
</script>

<template>
  <section class="brand-embed">
    <PageLoader v-if="loading" :label="t('common.loading')" />

    <div v-else class="brand-settings">
      <section class="brand-section">
        <header class="brand-section__header">
          <h2>{{ t('settings.backgroundsTitle') }}</h2>
          <p>{{ t('settings.backgroundsSubtitle') }}</p>
        </header>

        <div class="brand-grid">
          <article v-for="slot in slots" :key="slot.key" class="brand-card">
            <div
              class="brand-card__preview"
              :class="`brand-card__preview--${slot.preview}`"
              :style="
                slot.preview === 'tile'
                  ? { backgroundImage: toCssImageUrl(previewUrl(slot)) }
                  : slot.preview === 'cover'
                    ? loginHeroFitVars(brand.loginHeroFit)
                    : undefined
              "
            >
              <img
                v-if="slot.preview === 'cover' || slot.preview === 'contain'"
                :src="previewUrl(slot)"
                :alt="t(slot.titleKey)"
              />
            </div>

            <div class="brand-card__body">
              <div class="brand-card__head">
                <div>
                  <h2>{{ t(slot.titleKey) }}</h2>
                  <small class="brand-card__place">{{ t(slot.placementKey) }}</small>
                </div>
                <span class="brand-card__badge" :class="{ 'is-custom': isCustom(slot) }">
                  {{ isCustom(slot) ? t('settings.customImage') : t('settings.defaultImage') }}
                </span>
              </div>
              <p>{{ t(slot.hintKey) }}</p>

              <div class="brand-card__actions">
                <label v-if="canUpdate" class="brand-card__picker">
                  <input
                    type="file"
                    class="sr-only"
                    accept="image/jpeg,image/png,image/webp"
                    :disabled="uploading === slot.key || resetting === slot.key"
                    @change="onSelect(slot, $event)"
                  />
                  <span class="brand-card__picker-btn" :class="{ 'is-busy': uploading === slot.key }">
                    <i class="pi pi-image" aria-hidden="true" />
                    {{ uploading === slot.key ? t('common.loading') : t('settings.changeImage') }}
                  </span>
                </label>
                <Button
                  v-if="slot.key === 'login_hero'"
                  type="button"
                  text
                  icon="pi pi-sliders-h"
                  :label="t('settings.heroFitOpen')"
                  :disabled="uploading === slot.key"
                  @click="fitDrawerOpen = true"
                />
                <Button
                  v-if="canUpdate && isCustom(slot)"
                  type="button"
                  text
                  :label="t('settings.restoreDefault')"
                  :loading="resetting === slot.key"
                  :disabled="uploading === slot.key"
                  @click="resetSlot(slot)"
                />
              </div>
            </div>
          </article>
        </div>
      </section>

      <LoaderSettingsPanel :can-update="canUpdate" />
    </div>

    <AppStackDrawer
      v-model:visible="fitDrawerOpen"
      :level="1"
      :title="t('settings.heroFitTitle')"
      :subtitle="t('settings.heroFitHint')"
    >
      <LoginHeroStudio
        v-if="fitDrawerOpen"
        :image-url="localPreviews.login_hero || brand.loginHero"
        :copy="brand.loginHeroCopy"
        :can-update="canUpdate"
        :saving="savingFit"
        @save="saveHeroCopy"
      />
    </AppStackDrawer>
  </section>
</template>

<style scoped>
.brand-settings {
  display: flex;
  flex-direction: column;
  gap: 1.75rem;
}

.brand-section {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

.brand-section__header h2 {
  margin: 0;
  font-size: 1.2rem;
  color: var(--pj-navy);
}

.brand-section__header p {
  margin: 0.35rem 0 0;
  color: #64748b;
  font-size: 0.92rem;
}

.brand-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1.1rem;
}

.brand-card {
  overflow: hidden;
  border-radius: 1.1rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
}

.brand-card__preview {
  height: 180px;
  background: #0b2f6b;
}

.brand-card__preview--cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: var(--hero-x, 50%) var(--hero-y, 50%);
  transform: scale(var(--hero-zoom, 1));
  transform-origin: var(--hero-x, 50%) var(--hero-y, 50%);
  display: block;
}

.brand-card__preview--contain {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f8fafc;
}

.brand-card__preview--contain img {
  max-width: 78%;
  max-height: 78%;
  object-fit: contain;
  display: block;
}

.brand-card__preview--tile {
  background-color: #f4f6f9;
  background-repeat: repeat;
  background-size: 180px auto;
}

.brand-card__body {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  padding: 1rem 1.1rem 1.15rem;
}

.brand-card__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.brand-card__head h2 {
  margin: 0;
  font-size: 1.05rem;
  color: var(--pj-navy);
}

.brand-card__place {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--pj-navy);
  opacity: 0.72;
}

.brand-card__badge {
  flex-shrink: 0;
  border-radius: 999px;
  padding: 0.18rem 0.55rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: #475569;
  background: #f1f5f9;
}

.brand-card__badge.is-custom {
  color: #0b2f6b;
  background: color-mix(in srgb, var(--pj-gold, #ffcc00) 28%, white);
}

.brand-card__body p {
  margin: 0;
  color: #64748b;
  font-size: 0.88rem;
  line-height: 1.45;
}

.brand-card__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
}

.brand-card__picker {
  display: inline-flex;
  cursor: pointer;
}

.brand-card__picker-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border-radius: 0.65rem;
  padding: 0.55rem 0.9rem;
  font-size: 0.88rem;
  font-weight: 700;
  color: #fff;
  background: var(--pj-navy);
}

.brand-card__picker-btn.is-busy,
.brand-card__picker:has(input:disabled) .brand-card__picker-btn {
  opacity: 0.65;
  pointer-events: none;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

html.dark .brand-card {
  background: color-mix(in srgb, var(--pj-navy, #0b2f6b) 18%, #111827);
  border-color: rgba(255, 255, 255, 0.08);
}

html.dark .brand-section__header h2,
html.dark .brand-card__head h2,
html.dark .brand-card__place {
  color: #f8fafc;
}

html.dark .brand-card__body p {
  color: #94a3b8;
}
</style>
