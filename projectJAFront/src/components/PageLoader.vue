<script setup lang="ts">
import { computed } from 'vue'
import ClubLoader from '@/components/ClubLoader.vue'
import { useAuthStore } from '@/stores/auth'
import { useBrandStore } from '@/stores/brand'
import {
  clubLoaderKeyFromContext,
  readStoredClubLoader,
  type ClubLoaderKey,
} from '@/modules/auth/clubLogin'

const props = withDefaults(
  defineProps<{
    show?: boolean
    label?: string
    fullscreen?: boolean
    variant?: ClubLoaderKey
  }>(),
  {
    show: true,
    label: 'Cargando…',
    fullscreen: false,
  },
)

const auth = useAuthStore()
const brand = useBrandStore()

const resolvedVariant = computed<ClubLoaderKey>(() => {
  if (props.variant) {
    return props.variant
  }

  if (auth.contexto) {
    return clubLoaderKeyFromContext(auth.contexto)
  }

  return readStoredClubLoader() ?? 'neutral'
})

const resolvedPreset = computed(() => brand.loaderPreset(resolvedVariant.value))
</script>

<template>
  <div
    v-if="show"
    class="page-loader"
    :class="{ 'page-loader--fullscreen': fullscreen }"
  >
    <ClubLoader
      :variant="resolvedVariant"
      :preset="resolvedPreset"
      :label="label"
      :size="fullscreen ? 110 : 88"
    />
  </div>
</template>

<style scoped>
.page-loader {
  display: grid;
  place-items: center;
  min-height: 220px;
  width: 100%;
  padding: 2rem 1rem;
}

.page-loader--fullscreen {
  position: fixed;
  inset: 0;
  z-index: 1200;
  min-height: 100vh;
  background:
    radial-gradient(circle at 50% 40%, rgba(255, 204, 0, 0.12), transparent 42%),
    color-mix(in srgb, var(--pj-bg, #f4f6f9) 88%, transparent);
  backdrop-filter: blur(4px);
}
</style>
