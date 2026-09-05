<script setup lang="ts">
import { computed } from 'vue'
import { isRichTextEmpty, looksLikeHtml, sanitizeRichText } from '@/utils/richText'

const props = defineProps<{
  html?: string | null
  empty?: string
}>()

const isEmpty = computed(() => isRichTextEmpty(props.html))
const safeHtml = computed(() => {
  if (!props.html || isEmpty.value) return ''
  return looksLikeHtml(props.html) ? sanitizeRichText(props.html) : ''
})
const plainText = computed(() => {
  if (!props.html || isEmpty.value || looksLikeHtml(props.html)) return ''
  return props.html
})
</script>

<template>
  <p v-if="isEmpty" class="rich-view rich-view--empty">{{ empty }}</p>
  <div v-else-if="safeHtml" class="rich-view" v-html="safeHtml" />
  <p v-else class="rich-view rich-view--plain">{{ plainText }}</p>
</template>

<style scoped>
.rich-view {
  margin: 0;
  line-height: 1.55;
  font-size: 0.9rem;
  color: color-mix(in srgb, var(--pj-text) 88%, transparent);
}

.rich-view--empty {
  color: var(--pj-text-muted);
}

.rich-view--plain {
  white-space: pre-wrap;
}

.rich-view :deep(h2) {
  margin: 0 0 0.45rem;
  font-size: 1.12rem;
  color: var(--pj-text);
}

.rich-view :deep(h3) {
  margin: 0.2rem 0 0.4rem;
  font-size: 1rem;
  color: var(--pj-text);
}

.rich-view :deep(p) {
  margin: 0 0 0.45rem;
}

.rich-view :deep(ul),
.rich-view :deep(ol) {
  margin: 0 0 0.45rem;
  padding-left: 1.3rem;
}

.rich-view :deep(a) {
  color: var(--pj-navy);
}
</style>
