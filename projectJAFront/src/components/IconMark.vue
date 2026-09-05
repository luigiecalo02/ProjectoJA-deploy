<script setup lang="ts">
import { computed } from 'vue'
import { iconImageSrc, isImageIcon } from '@/utils/iconMark'

const props = withDefaults(
  defineProps<{
    icono?: string | null
    fallback?: string
  }>(),
  {
    fallback: 'pi pi-tag',
  },
)

const imageSrc = computed(() => iconImageSrc(props.icono))
const primeClass = computed(() => {
  if (isImageIcon(props.icono)) return ''
  return props.icono?.trim() || props.fallback
})
</script>

<template>
  <img v-if="imageSrc" class="icon-mark__img" :src="imageSrc" alt="" />
  <i v-else :class="primeClass" />
</template>

<style scoped>
.icon-mark__img {
  width: 1.15em;
  height: 1.15em;
  object-fit: contain;
  display: block;
}
</style>
