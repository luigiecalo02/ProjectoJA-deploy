<script setup lang="ts">
import conquistadoresLogo from '@/assets/brand/app-icon.png'

defineProps<{
  label?: string
  size?: number
}>()
</script>

<template>
  <div class="cq-loader" role="status" :aria-label="label || 'Cargando'">
    <div class="cq-loader__orb" :style="{ width: `${size || 96}px`, height: `${size || 96}px` }">
      <span class="cq-loader__ring" aria-hidden="true" />
      <img
        class="cq-loader__mark"
        :src="conquistadoresLogo"
        alt=""
        draggable="false"
      />
    </div>
    <p v-if="label" class="cq-loader__label">{{ label }}</p>
  </div>
</template>

<style scoped>
.cq-loader {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 0.85rem;
}

.cq-loader__orb {
  position: relative;
  display: grid;
  place-items: center;
}

.cq-loader__ring {
  position: absolute;
  inset: -10%;
  border-radius: 50%;
  border: 2px solid transparent;
  border-top-color: #ffcc00;
  border-right-color: rgba(237, 28, 36, 0.55);
  animation: cq-spin 1.1s linear infinite;
}

.cq-loader__mark {
  width: 82%;
  height: auto;
  display: block;
  user-select: none;
  filter: drop-shadow(0 10px 16px rgba(11, 47, 107, 0.25));
  animation: cq-float 1.5s ease-in-out infinite;
}

.cq-loader__label {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--pj-navy, #0b2f6b);
  letter-spacing: 0.02em;
}

@keyframes cq-float {
  0%,
  100% {
    transform: translateY(0) scale(1);
  }
  50% {
    transform: translateY(-7px) scale(1.03);
  }
}

@keyframes cq-spin {
  to {
    transform: rotate(360deg);
  }
}

html.dark .cq-loader__label {
  color: #ffcc00;
}
</style>
