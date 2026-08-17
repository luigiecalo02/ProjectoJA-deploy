<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Select from 'primevue/select'
import ClubLoader from '@/components/ClubLoader.vue'
import { getApiErrorMessage } from '@/services/api'
import { brandSettingsService } from '@/services/brandSettingsService'
import { useBrandStore } from '@/stores/brand'
import {
  LOADER_KEYS,
  LOADER_SPEEDS,
  LOGO_ANIMATIONS,
  RING_ANIMATIONS,
  mergeLoaderPreset,
  type ClubLoaderKey,
  type LoaderPreset,
} from '@/modules/auth/clubLogin'

defineProps<{
  canUpdate: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const brand = useBrandStore()

const selectedKey = ref<ClubLoaderKey>('conquistadores')
const draft = reactive<LoaderPreset>(mergeLoaderPreset('conquistadores'))
const saving = ref(false)
const uploading = ref(false)
const localPreviewUrl = ref<string | null>(null)

const previewPreset = computed<LoaderPreset>(() => ({
  key: selectedKey.value,
  logo_url: draft.logo_url,
  ring_top: draft.ring_top,
  ring_right: draft.ring_right,
  glow: draft.glow,
  label_color: draft.label_color,
  logo_animation: draft.logo_animation,
  ring_animation: draft.ring_animation,
  speed: draft.speed,
}))

const logoAnimationOptions = LOGO_ANIMATIONS.map((value) => ({
  value,
  label: t(`settings.logoAnim_${value}`),
}))

const ringAnimationOptions = RING_ANIMATIONS.map((value) => ({
  value,
  label: t(`settings.ringAnim_${value}`),
}))

const speedOptions = LOADER_SPEEDS.map((value) => ({
  value,
  label: t(`settings.speed_${value}`),
}))

const loaderTabLabels: Record<ClubLoaderKey, string> = {
  neutral: 'settings.loaderNeutral',
  aventureros: 'settings.loaderAventureros',
  conquistadores: 'settings.loaderConquistadores',
  guias_mayores: 'settings.loaderGuias',
}

function clearLocalPreview(): void {
  if (localPreviewUrl.value) {
    URL.revokeObjectURL(localPreviewUrl.value)
    localPreviewUrl.value = null
  }
}

function syncDraft(key: ClubLoaderKey): void {
  clearLocalPreview()
  Object.assign(draft, mergeLoaderPreset(key, brand.loaderPreset(key)))
}

watch(selectedKey, (key) => syncDraft(key), { immediate: true })

function notifyError(error: unknown): void {
  toast.add({
    severity: 'error',
    summary: t('common.error'),
    detail: getApiErrorMessage(error),
    life: 4000,
  })
}

async function save(): Promise<void> {
  saving.value = true
  try {
    brand.apply(
      await brandSettingsService.updateLoader(selectedKey.value, {
        ring_top: draft.ring_top,
        ring_right: draft.ring_right,
        glow: draft.glow,
        label_color: draft.label_color,
        logo_animation: draft.logo_animation,
        ring_animation: draft.ring_animation,
        speed: draft.speed,
      }),
    )
    syncDraft(selectedKey.value)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.loaderSaved'),
      life: 2500,
    })
  } catch (error) {
    notifyError(error)
  } finally {
    saving.value = false
  }
}

async function onLogoSelect(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  clearLocalPreview()
  localPreviewUrl.value = URL.createObjectURL(file)
  draft.logo_url = localPreviewUrl.value

  uploading.value = true
  try {
    brand.apply(await brandSettingsService.uploadLoaderLogo(selectedKey.value, file))
    const stored = brand.loaderPreset(selectedKey.value)
    const preview = draft.logo_url
    Object.assign(draft, mergeLoaderPreset(selectedKey.value, stored))
    if (!draft.logo_url && preview) {
      draft.logo_url = preview
    }
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.uploadSuccess'),
      life: 2500,
    })
  } catch (error) {
    notifyError(error)
  } finally {
    uploading.value = false
  }
}

onBeforeUnmount(() => {
  clearLocalPreview()
})

async function resetLogo(): Promise<void> {
  uploading.value = true
  try {
    brand.apply(await brandSettingsService.resetLoaderLogo(selectedKey.value))
    syncDraft(selectedKey.value)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.resetSuccess'),
      life: 2500,
    })
  } catch (error) {
    notifyError(error)
  } finally {
    uploading.value = false
  }
}

async function resetAll(): Promise<void> {
  saving.value = true
  try {
    brand.apply(await brandSettingsService.resetLoader(selectedKey.value))
    syncDraft(selectedKey.value)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('settings.loaderReset'),
      life: 2500,
    })
  } catch (error) {
    notifyError(error)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="loader-panel">
    <header class="loader-panel__header">
      <div>
        <h2>{{ t('settings.loadersTitle') }}</h2>
        <p>{{ t('settings.loadersSubtitle') }}</p>
        <p class="loader-panel__hint">{{ t('settings.loadersAdminHint') }}</p>
      </div>
    </header>

    <div class="loader-tabs" role="tablist">
      <button
        v-for="key in LOADER_KEYS"
        :key="key"
        type="button"
        role="tab"
        class="loader-tabs__btn"
        :class="{ 'is-active': selectedKey === key }"
        :aria-selected="selectedKey === key"
        @click="selectedKey = key"
      >
        {{ t(loaderTabLabels[key]) }}
      </button>
    </div>

    <div class="loader-editor">
      <aside class="loader-preview">
        <ClubLoader
          :key="`${selectedKey}-${previewPreset.logo_url}-${previewPreset.logo_animation}-${previewPreset.ring_animation}-${previewPreset.speed}`"
          :variant="selectedKey"
          :preset="previewPreset"
          :label="t('common.loading')"
          :size="120"
        />
        <small>{{ t('settings.loaderPreview') }}</small>
      </aside>

      <div class="loader-fields">
        <div class="color-grid">
          <label>
            <span>{{ t('settings.ringTop') }}</span>
            <input v-model="draft.ring_top" type="color" :disabled="!canUpdate" />
          </label>
          <label>
            <span>{{ t('settings.ringRight') }}</span>
            <input v-model="draft.ring_right" type="color" :disabled="!canUpdate" />
          </label>
          <label>
            <span>{{ t('settings.glow') }}</span>
            <input v-model="draft.glow" type="color" :disabled="!canUpdate" />
          </label>
          <label>
            <span>{{ t('settings.labelColor') }}</span>
            <input v-model="draft.label_color" type="color" :disabled="!canUpdate" />
          </label>
        </div>

        <div class="select-grid">
          <label>
            <span>{{ t('settings.logoAnimation') }}</span>
            <Select
              v-model="draft.logo_animation"
              :options="logoAnimationOptions"
              option-label="label"
              option-value="value"
              fluid
              :disabled="!canUpdate"
            />
          </label>
          <label>
            <span>{{ t('settings.ringAnimation') }}</span>
            <Select
              v-model="draft.ring_animation"
              :options="ringAnimationOptions"
              option-label="label"
              option-value="value"
              fluid
              :disabled="!canUpdate"
            />
          </label>
          <label>
            <span>{{ t('settings.speed') }}</span>
            <Select
              v-model="draft.speed"
              :options="speedOptions"
              option-label="label"
              option-value="value"
              fluid
              :disabled="!canUpdate"
            />
          </label>
        </div>

        <div v-if="canUpdate" class="loader-actions">
          <label class="loader-picker">
            <input
              type="file"
              class="sr-only"
              accept="image/jpeg,image/png,image/webp"
              :disabled="saving || uploading"
              @change="onLogoSelect"
            />
            <span class="loader-picker__btn" :class="{ 'is-busy': uploading }">
              <i class="pi pi-image" aria-hidden="true" />
              {{ uploading ? t('common.loading') : t('settings.changeLogo') }}
            </span>
          </label>
          <Button
            v-if="draft.logo_url"
            type="button"
            text
            :label="t('settings.restoreLogo')"
            :disabled="saving || uploading"
            @click="resetLogo"
          />
          <Button
            type="button"
            :label="t('common.save')"
            icon="pi pi-check"
            :loading="saving"
            :disabled="saving || uploading"
            @click="save"
          />
          <Button
            type="button"
            text
            :label="t('settings.restoreLoader')"
            :disabled="saving || uploading"
            @click="resetAll"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.loader-panel {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.loader-panel__header h2 {
  margin: 0;
  font-size: 1.2rem;
  color: var(--pj-navy);
}

.loader-panel__header p {
  margin: 0.35rem 0 0;
  color: #64748b;
  font-size: 0.92rem;
}

.loader-panel__hint {
  font-weight: 600;
  color: var(--pj-navy) !important;
}

.loader-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.loader-tabs__btn {
  border: 1px solid #e2e8f0;
  background: #fff;
  border-radius: 999px;
  padding: 0.4rem 0.85rem;
  font-size: 0.82rem;
  font-weight: 700;
  color: #475569;
  cursor: pointer;
}

.loader-tabs__btn.is-active {
  background: var(--pj-navy);
  border-color: var(--pj-navy);
  color: #fff;
}

.loader-editor {
  display: grid;
  grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
  gap: 1.25rem;
  padding: 1.15rem;
  border-radius: 1.1rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
}

.loader-preview {
  display: grid;
  place-items: center;
  align-content: center;
  gap: 0.7rem;
  min-height: 220px;
  border-radius: 0.9rem;
  background: #f8fafc;
}

.loader-preview small {
  color: #64748b;
}

.loader-fields {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.color-grid,
.select-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
}

.loader-fields label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.82rem;
  font-weight: 600;
  color: #334155;
}

.loader-fields input[type='color'] {
  width: 100%;
  height: 2.4rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.6rem;
  background: #fff;
  padding: 0.2rem;
}

.loader-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
}

.loader-picker {
  display: inline-flex;
  cursor: pointer;
}

.loader-picker__btn {
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

.loader-picker__btn.is-busy,
.loader-picker:has(input:disabled) .loader-picker__btn {
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

@media (max-width: 800px) {
  .loader-editor {
    grid-template-columns: 1fr;
  }
}

html.dark .loader-panel__header h2,
html.dark .loader-fields label {
  color: #e2e8f0;
}

html.dark .loader-editor,
html.dark .loader-tabs__btn {
  background: color-mix(in srgb, var(--pj-navy, #0b2f6b) 18%, #111827);
  border-color: rgba(255, 255, 255, 0.08);
  color: #e2e8f0;
}

html.dark .loader-preview {
  background: rgba(15, 23, 42, 0.45);
}
</style>
