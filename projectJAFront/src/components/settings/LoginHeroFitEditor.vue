<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import {
  DEFAULT_LOGIN_HERO_FIT,
  LOGIN_HERO_ZOOM_MAX,
  LOGIN_HERO_ZOOM_MIN,
  isSameLoginHeroFit,
  loginHeroFitVars,
  normalizeLoginHeroFit,
  type LoginHeroFit,
} from '@/modules/settings/loginHeroFit'

const props = defineProps<{
  imageUrl: string
  fit: LoginHeroFit
  canUpdate: boolean
  saving?: boolean
  embedded?: boolean
}>()

const emit = defineEmits<{
  save: [fit: LoginHeroFit]
  'update:fit': [fit: LoginHeroFit]
}>()

const { t } = useI18n()
const draft = reactive<LoginHeroFit>(normalizeLoginHeroFit(props.fit))
const dragging = reactive({ active: false, x: 0, y: 0 })

watch(
  () => props.fit,
  (next) => Object.assign(draft, normalizeLoginHeroFit(next)),
)

watch(
  draft,
  (next) => {
    const normalized = normalizeLoginHeroFit(next)
    if (!isSameLoginHeroFit(normalized, normalizeLoginHeroFit(props.fit))) {
      emit('update:fit', normalized)
    }
  },
  { deep: true },
)

const dirty = computed(() => !isSameLoginHeroFit(normalizeLoginHeroFit(draft), normalizeLoginHeroFit(props.fit)))
const stageStyle = computed(() => loginHeroFitVars(draft))
const zoomPercent = computed(() => Math.round(draft.zoom * 100))

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value))
}

function onPointerDown(event: PointerEvent): void {
  if (!props.canUpdate || event.button !== 0) return
  const target = event.currentTarget as HTMLElement
  target.setPointerCapture(event.pointerId)
  dragging.active = true
  dragging.x = event.clientX
  dragging.y = event.clientY
}

function onPointerMove(event: PointerEvent): void {
  if (!dragging.active) return
  const target = event.currentTarget as HTMLElement
  const rect = target.getBoundingClientRect()
  if (rect.width === 0 || rect.height === 0) return

  const dx = event.clientX - dragging.x
  const dy = event.clientY - dragging.y
  dragging.x = event.clientX
  dragging.y = event.clientY

  draft.x = clamp(draft.x - (dx / rect.width) * (100 / draft.zoom), 0, 100)
  draft.y = clamp(draft.y - (dy / rect.height) * (100 / draft.zoom), 0, 100)
}

function onPointerUp(event: PointerEvent): void {
  const target = event.currentTarget as HTMLElement
  if (target.hasPointerCapture(event.pointerId)) {
    target.releasePointerCapture(event.pointerId)
  }
  dragging.active = false
}

function onWheel(event: WheelEvent): void {
  if (!props.canUpdate) return
  event.preventDefault()
  const step = event.deltaY > 0 ? -0.08 : 0.08
  draft.zoom = clamp(Number((draft.zoom + step).toFixed(2)), LOGIN_HERO_ZOOM_MIN, LOGIN_HERO_ZOOM_MAX)
}

function resetFrame(): void {
  Object.assign(draft, { ...DEFAULT_LOGIN_HERO_FIT })
}

function save(): void {
  emit('save', normalizeLoginHeroFit(draft))
}
</script>

<template>
  <div class="hero-fit">
    <div
      class="hero-fit__stage"
      :class="{ 'is-dragging': dragging.active, 'is-readonly': !canUpdate }"
      :style="stageStyle"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @wheel="onWheel"
    >
      <img class="hero-fit__image" :src="imageUrl" alt="" draggable="false" />
      <div class="hero-fit__overlay" />
      <div v-if="!embedded" class="hero-fit__copy">
        <span>{{ t('auth.heroLine1') }}</span>
        <em>{{ t('auth.heroLine2') }}</em>
      </div>
      <p v-if="!embedded" class="hero-fit__hint">{{ t('settings.heroFitDrag') }}</p>
    </div>

    <div class="hero-fit__controls">
      <label class="hero-fit__zoom">
        <span>{{ t('settings.heroFitZoom') }} · {{ zoomPercent }}%</span>
        <input
          v-model.number="draft.zoom"
          type="range"
          :min="LOGIN_HERO_ZOOM_MIN"
          :max="LOGIN_HERO_ZOOM_MAX"
          step="0.05"
          :disabled="!canUpdate"
        />
      </label>
      <div class="hero-fit__actions">
        <Button
          type="button"
          text
          :label="t('settings.heroFitReset')"
          :disabled="!canUpdate || saving"
          @click="resetFrame"
        />
        <Button
          v-if="!embedded"
          type="button"
          icon="pi pi-check"
          :label="t('settings.heroFitSave')"
          :loading="saving"
          :disabled="!canUpdate || !dirty || saving"
          @click="save"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.hero-fit {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.hero-fit__stage {
  position: relative;
  overflow: hidden;
  isolation: isolate;
  min-height: 320px;
  aspect-ratio: 16 / 10;
  border-radius: 1rem;
  background: #07122a;
  cursor: grab;
  user-select: none;
  touch-action: none;
}

.hero-fit__stage.is-dragging {
  cursor: grabbing;
}

.hero-fit__stage.is-readonly {
  cursor: default;
}

.hero-fit__image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: var(--hero-x, 50%) var(--hero-y, 50%);
  transform: scale(var(--hero-zoom, 1));
  transform-origin: var(--hero-x, 50%) var(--hero-y, 50%);
  pointer-events: none;
}

.hero-fit__overlay {
  position: absolute;
  inset: 0;
  z-index: 1;
  background: linear-gradient(
    180deg,
    rgba(7, 18, 42, 0.18) 0%,
    rgba(7, 18, 42, 0.42) 55%,
    rgba(7, 18, 42, 0.72) 100%
  );
}

.hero-fit__copy {
  position: absolute;
  left: 1.1rem;
  bottom: 2.6rem;
  z-index: 2;
  display: flex;
  flex-direction: column;
  color: #fff;
  font-weight: 800;
  font-size: clamp(1.15rem, 2.4vw, 1.7rem);
  line-height: 1.05;
  pointer-events: none;
}

.hero-fit__copy em {
  font-family: 'Dancing Script', cursive;
  font-style: normal;
  color: var(--pj-gold, #ffcc00);
  font-size: 1.28em;
}

.hero-fit__hint {
  position: absolute;
  left: 1.1rem;
  bottom: 0.75rem;
  z-index: 2;
  margin: 0;
  color: rgba(255, 255, 255, 0.82);
  font-size: 0.78rem;
  font-weight: 600;
  pointer-events: none;
}

.hero-fit__controls {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.hero-fit__zoom {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  min-width: min(100%, 260px);
  flex: 1;
  font-size: 0.82rem;
  font-weight: 700;
  color: #334155;
}

.hero-fit__zoom input {
  width: 100%;
}

.hero-fit__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

@media (max-width: 720px) {
  .hero-fit__stage {
    border-radius: 1rem;
    min-height: 240px;
  }
}

html.dark .hero-fit__zoom {
  color: #e2e8f0;
}
</style>
