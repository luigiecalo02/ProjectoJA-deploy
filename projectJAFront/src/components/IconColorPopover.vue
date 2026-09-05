<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import ColorAlphaPicker from '@/components/terrenos/ColorAlphaPicker.vue'
import PrimeIconPicker from '@/components/PrimeIconPicker.vue'
import { clampIconSize, ICON_SIZE_DEFAULT, ICON_SIZE_MAX, ICON_SIZE_MIN } from '@/utils/iconSize'

const props = withDefaults(
  defineProps<{
    icono?: string | null
    color?: string | null
    tamano?: number | null
    showSize?: boolean
    variant?: 'cover' | 'compact'
    fallbackIcon?: string
  }>(),
  {
    icono: 'pi pi-calendar',
    color: '#1e3a5f',
    tamano: ICON_SIZE_DEFAULT,
    showSize: false,
    variant: 'compact',
    fallbackIcon: 'pi pi-calendar',
  },
)

const emit = defineEmits<{
  'update:icono': [value: string]
  'update:color': [value: string]
  'update:tamano': [value: number]
}>()

const { t } = useI18n()
const popover = ref<{ toggle: (event: Event) => void } | null>(null)
const sizeEnabled = computed(() => props.showSize || props.variant === 'cover')
const previewSize = computed(() => clampIconSize(props.tamano))

function toggle(event: Event): void {
  popover.value?.toggle(event)
}

function onSize(value: string): void {
  emit('update:tamano', clampIconSize(Number(value)))
}
</script>

<template>
  <div class="icon-color" :class="`icon-color--${variant}`">
    <button
      type="button"
      class="icon-color__trigger"
      :style="{ color: color || undefined }"
      :aria-label="t('events.wizard.subVisualPick')"
      :title="t('events.wizard.subVisualPick')"
      @click="toggle"
    >
      <i
        :class="icono || fallbackIcon"
        :style="sizeEnabled ? { fontSize: `${previewSize}px` } : undefined"
      />
      <span class="icon-color__edit" aria-hidden="true">
        <i class="pi pi-pencil" />
      </span>
    </button>
    <Popover ref="popover" append-to="body" class="icon-color-overlay">
      <div class="icon-color__panel">
        <p class="icon-color__lead">{{ t('events.wizard.subVisualPick') }}</p>
        <ColorAlphaPicker
          :model-value="color"
          compact
          @update:model-value="emit('update:color', $event)"
        />
        <label v-if="sizeEnabled" class="icon-color__size">
          <span>{{ t('common.tamano') }} · {{ previewSize }}px</span>
          <input
            type="range"
            :min="ICON_SIZE_MIN"
            :max="ICON_SIZE_MAX"
            step="2"
            :value="previewSize"
            :aria-label="t('common.tamano')"
            @input="onSize(($event.target as HTMLInputElement).value)"
          />
        </label>
        <PrimeIconPicker
          :model-value="icono"
          :color="color"
          @update:model-value="emit('update:icono', $event)"
        />
      </div>
    </Popover>
  </div>
</template>

<style scoped>
.icon-color--cover {
  width: 100%;
}

.icon-color__trigger {
  position: relative;
  display: grid;
  place-items: center;
  border: 1px solid color-mix(in srgb, currentColor 28%, transparent);
  background: color-mix(in srgb, currentColor 10%, #fff);
  color: var(--pj-navy, #1e3a5f);
  cursor: pointer;
}

.icon-color--compact .icon-color__trigger {
  width: 3.1rem;
  height: 3.1rem;
  border-radius: 12px;
  font-size: 1.35rem;
}

.icon-color--cover .icon-color__trigger {
  width: 100%;
  min-height: 8.5rem;
  border-radius: 12px;
}

.icon-color__trigger:hover,
.icon-color__trigger:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 22%, transparent);
}

.icon-color__edit {
  position: absolute;
  right: 0.4rem;
  bottom: 0.4rem;
  width: 1.45rem;
  height: 1.45rem;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: #fff;
  color: var(--pj-navy, #1e3a5f);
  font-size: 0.7rem;
  box-shadow: 0 1px 4px color-mix(in srgb, #0f172a 18%, transparent);
}

.icon-color--compact .icon-color__edit {
  width: 1.15rem;
  height: 1.15rem;
  right: 0.2rem;
  bottom: 0.2rem;
  font-size: 0.55rem;
}

.icon-color__panel {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  width: min(22rem, 86vw);
}

.icon-color__lead {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 650;
  color: var(--pj-text-muted);
}

.icon-color__size {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.75rem;
  color: var(--pj-muted, #667);
}

.icon-color__size input[type='range'] {
  width: 100%;
  accent-color: var(--pj-accent, #0d47a1);
}
</style>

<style>
.icon-color-overlay.p-popover {
  z-index: 2200;
}
</style>
