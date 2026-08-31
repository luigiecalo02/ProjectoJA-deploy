<script setup lang="ts">
import { computed } from 'vue'
import {
  DEFAULT_LOADER_LOGOS,
  mergeLoaderPreset,
  type ClubLoaderKey,
} from '@/modules/auth/clubLogin'
import type { LoaderPreset } from '@/modules/settings/types'
import { colorToCss, parseMapColor, serializeMapColor } from '@/utils/color'

const props = withDefaults(
  defineProps<{
    variant?: ClubLoaderKey
    preset?: LoaderPreset | null
    label?: string
    size?: number
  }>(),
  {
    variant: 'neutral',
    size: 96,
  },
)

const theme = computed(() =>
  mergeLoaderPreset(props.variant, props.preset ? { ...props.preset } : null),
)

const logoSrc = computed(() => props.preset?.logo_url || DEFAULT_LOADER_LOGOS[props.variant])

const duration = computed(() => {
  if (theme.value.speed === 'slow') return '1.9s'
  if (theme.value.speed === 'fast') return '0.7s'
  return '1.2s'
})

const glowShadow = computed(() => {
  const parsed = parseMapColor(theme.value.glow, '#ffcc00', 1)
  return colorToCss(serializeMapColor(parsed.hex, parsed.alpha * 0.25, parsed.hex), parsed.hex, 0.25)
})
</script>

<template>
  <div class="club-loader" role="status" :aria-label="label || 'Cargando'">
    <div class="club-loader__orb" :style="{ width: `${size}px`, height: `${size}px` }">
      <span
        class="club-loader__ring"
        :class="`is-${theme.ring_animation}`"
        :style="{
          borderTopColor: colorToCss(theme.ring_top, theme.ring_top, 1),
          borderRightColor: colorToCss(theme.ring_right, theme.ring_right, 1),
          animationDuration: duration,
        }"
        aria-hidden="true"
      />
      <img
        class="club-loader__mark"
        :class="`is-${theme.logo_animation}`"
        :key="logoSrc"
        :src="logoSrc"
        alt=""
        draggable="false"
        :style="{
          filter: `drop-shadow(0 10px 16px ${glowShadow})`,
          animationDuration: duration,
        }"
      />
    </div>
    <p v-if="label" class="club-loader__label" :style="{ color: colorToCss(theme.label_color, theme.label_color, 1) }">
      {{ label }}
    </p>
  </div>
</template>

<style scoped>
.club-loader {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 0.85rem;
}

.club-loader__orb {
  position: relative;
  display: grid;
  place-items: center;
}

.club-loader__ring {
  position: absolute;
  inset: -10%;
  border-radius: 50%;
  border: 2px solid transparent;
}

.club-loader__ring.is-spin {
  animation-name: club-spin;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}

.club-loader__ring.is-pulse {
  animation-name: club-ring-pulse;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
}

.club-loader__mark {
  position: relative;
  z-index: 1;
  width: 68%;
  height: 68%;
  object-fit: contain;
  object-position: center;
  display: block;
  user-select: none;
}

.club-loader__mark.is-float {
  animation-name: club-float;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
}

.club-loader__mark.is-pulse {
  animation-name: club-pulse;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
}

.club-loader__mark.is-spin {
  animation-name: club-spin;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}

.club-loader__mark.is-bounce {
  animation-name: club-bounce;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
}

.club-loader__label {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
  letter-spacing: 0.02em;
}

@keyframes club-float {
  0%,
  100% {
    transform: translateY(0) scale(1);
  }
  50% {
    transform: translateY(-7px) scale(1.03);
  }
}

@keyframes club-pulse {
  0%,
  100% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.08);
    opacity: 0.86;
  }
}

@keyframes club-bounce {
  0%,
  100% {
    transform: translateY(0);
  }
  40% {
    transform: translateY(-10px);
  }
  60% {
    transform: translateY(-3px);
  }
}

@keyframes club-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes club-ring-pulse {
  0%,
  100% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.12);
    opacity: 0.45;
  }
}
</style>
