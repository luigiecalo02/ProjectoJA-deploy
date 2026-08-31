<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  DEFAULT_COLOR_ALPHA,
  DEFAULT_COLOR_HEX,
  colorToCss,
  parseMapColor,
  serializeMapColor,
} from '@/utils/color'

const props = withDefaults(
  defineProps<{
    modelValue?: string | null
    disabled?: boolean
    compact?: boolean
    defaultHex?: string
    defaultAlpha?: number
  }>(),
  {
    defaultHex: DEFAULT_COLOR_HEX,
    defaultAlpha: DEFAULT_COLOR_ALPHA,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const { t } = useI18n()
const parsed = computed(() => parseMapColor(props.modelValue, props.defaultHex, props.defaultAlpha))

function onColor(value: string): void {
  emit('update:modelValue', serializeMapColor(value, parsed.value.alpha, props.defaultHex))
}

function onAlpha(value: string): void {
  emit('update:modelValue', serializeMapColor(parsed.value.hex, Number(value) / 100, props.defaultHex))
}
</script>

<template>
  <div class="color-alpha" :class="{ 'color-alpha--compact': compact }">
    <div class="color-alpha__preview" :title="t('common.color')">
      <span
        class="color-alpha__swatch"
        :style="{ background: colorToCss(modelValue, defaultHex, defaultAlpha) }"
      />
      <input
        type="color"
        class="color-alpha__picker"
        :value="parsed.hex"
        :disabled="disabled"
        :aria-label="t('common.color')"
        @input="onColor(($event.target as HTMLInputElement).value)"
      />
    </div>
    <label class="color-alpha__alpha">
      <span>{{ t('common.transparencia') }}</span>
      <input
        type="range"
        min="8"
        max="100"
        step="1"
        :value="Math.round(parsed.alpha * 100)"
        :disabled="disabled"
        :aria-label="t('common.transparencia')"
        @input="onAlpha(($event.target as HTMLInputElement).value)"
      />
    </label>
  </div>
</template>

<style scoped>
.color-alpha {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.color-alpha--compact {
  gap: 0.5rem;
}
.color-alpha__preview {
  position: relative;
  flex: 0 0 2.4rem;
  width: 2.4rem;
  height: 2.2rem;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid var(--pj-border, #ddd);
  background:
    linear-gradient(45deg, #ccc 25%, transparent 25%) 0 0 / 8px 8px,
    linear-gradient(-45deg, #ccc 25%, transparent 25%) 0 4px / 8px 8px,
    linear-gradient(45deg, transparent 75%, #ccc 75%) 4px -4px / 8px 8px,
    linear-gradient(-45deg, transparent 75%, #ccc 75%) -4px 0 / 8px 8px,
    #fff;
}
.color-alpha--compact .color-alpha__preview {
  flex-basis: 2rem;
  width: 2rem;
  height: 1.75rem;
}
.color-alpha__swatch {
  display: block;
  width: 100%;
  height: 100%;
}
.color-alpha__picker {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  padding: 0;
  border: 0;
  opacity: 0;
  cursor: pointer;
}
.color-alpha__picker:disabled {
  cursor: default;
}
.color-alpha__alpha {
  display: flex;
  flex: 1;
  min-width: 7rem;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.75rem;
  color: var(--pj-muted, #667);
}
.color-alpha--compact .color-alpha__alpha span {
  display: none;
}
.color-alpha__alpha input[type='range'] {
  width: 100%;
  accent-color: var(--pj-accent, #0d47a1);
}
</style>
