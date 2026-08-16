<script setup lang="ts">
import ConquistadoresLoader from '@/components/ConquistadoresLoader.vue'

withDefaults(
  defineProps<{
    show?: boolean
    label?: string
    fullscreen?: boolean
  }>(),
  {
    show: true,
    label: 'Cargando…',
    fullscreen: false,
  },
)
</script>

<template>
  <div
    v-if="show"
    class="page-loader"
    :class="{ 'page-loader--fullscreen': fullscreen }"
  >
    <ConquistadoresLoader :label="label" :size="fullscreen ? 110 : 88" />
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
