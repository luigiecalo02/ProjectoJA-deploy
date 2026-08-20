<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import { computed } from 'vue'
import { usePageChrome, type PageChromeAction } from '@/composables/usePageChrome'

const props = defineProps<{
  title: string
  subtitle?: string
  backTo?: RouteLocationRaw | null
  actions?: PageChromeAction[]
}>()

usePageChrome(() => ({
  title: props.title,
  subtitle: props.subtitle,
  backTo: props.backTo ?? null,
  actions: props.actions ?? [],
}))

const hasCopy = computed(() => Boolean(props.title || props.subtitle))
</script>

<template>
  <header v-if="hasCopy" class="pj-page__header page-header">
    <div>
      <h1 class="pj-page__title">{{ title }}</h1>
      <p v-if="subtitle" class="pj-page__subtitle">{{ subtitle }}</p>
    </div>
  </header>
</template>
