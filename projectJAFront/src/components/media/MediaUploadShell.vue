<script setup lang="ts">
import type { MediaUploadKind } from '@/modules/media/types'

withDefaults(
  defineProps<{
    kind: MediaUploadKind
    icon: string
    title: string
    subtitle?: string
    meta?: string
    hint?: string
    dense?: boolean
  }>(),
  { dense: false },
)
</script>

<template>
  <article class="media-card" :class="[`is-${kind}`, { 'is-dense': dense }]">
    <header class="media-card__head">
      <i :class="icon" aria-hidden="true" />
      <div>
        <h3>{{ title }}</h3>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
    </header>
    <slot />
    <p v-if="meta" class="media-card__meta">{{ meta }}</p>
    <slot name="actions" />
    <p v-if="hint" class="media-card__hint">
      <i class="pi pi-info-circle" aria-hidden="true" />
      <span>{{ hint }}</span>
    </p>
  </article>
</template>

<style scoped>
.media-card {
  display: grid;
  gap: 0.85rem;
  padding: 1rem 1.05rem 1.05rem;
  border: 1px solid var(--pj-border);
  border-radius: 18px;
  background: var(--pj-bg-elevated, #fff);
  box-shadow: 0 8px 24px rgb(15 23 42 / 6%);
}
.media-card__head {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
}
.media-card__head i {
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 10px;
  display: grid;
  place-items: center;
  font-size: 1rem;
}
.media-card__head h3 {
  margin: 0;
  font-size: 1rem;
  font-weight: 800;
  color: var(--pj-navy, #0f172a);
}
.media-card__head p {
  margin: 0.15rem 0 0;
  font-size: 0.8rem;
  color: var(--pj-text-muted);
  line-height: 1.35;
}
.media-card__meta {
  margin: 0;
  font-size: 0.75rem;
  color: var(--pj-text-muted);
}
.media-card__hint {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0;
  padding: 0.65rem 0.75rem;
  border-radius: 12px;
  font-size: 0.78rem;
  line-height: 1.4;
}
.is-profile .media-card__head i,
.is-documents .media-card__head i {
  background: #dbeafe;
  color: #2563eb;
}
.is-cover .media-card__head i {
  background: #dcfce7;
  color: #16a34a;
}
.is-gallery .media-card__head i {
  background: #ede9fe;
  color: #7c3aed;
}
.is-profile .media-card__hint,
.is-documents .media-card__hint {
  background: #eff6ff;
  color: #1d4ed8;
}
.is-cover .media-card__hint {
  background: #f0fdf4;
  color: #166534;
}
.is-gallery .media-card__hint {
  background: #f5f3ff;
  color: #6d28d9;
}
.is-dense {
  gap: 0.55rem;
  padding: 0.75rem;
  box-shadow: none;
}
.is-dense .media-card__head i {
  width: 1.7rem;
  height: 1.7rem;
  font-size: 0.85rem;
}
.is-dense .media-card__head h3 {
  font-size: 0.92rem;
}
.is-dense .media-card__meta {
  font-size: 0.7rem;
}
</style>
