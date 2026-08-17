<script setup lang="ts">
import ClubLoader from '@/components/ClubLoader.vue'

defineProps<{
  label?: string
  size?: number
}>()
</script>

<template>
  <ClubLoader variant="conquistadores" :label="label" :size="size" />
</template>
