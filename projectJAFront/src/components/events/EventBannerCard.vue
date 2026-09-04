<script setup lang="ts">
defineProps<{
  name: string
  bannerUrl?: string | null
  logoUrl?: string | null
  statusLabel: string
  statusCss?: string
  audienceLabel?: string | null
  datesLabel: string
  placeLabel: string
  description?: string | null
  cupoLabel: string
  scoreLabel: string
  cupoCaption: string
  scoreCaption: string
}>()
</script>

<template>
  <article class="event-banner-card">
    <div class="event-banner-card__media">
      <img v-if="bannerUrl" :src="bannerUrl" :alt="name" />
      <div v-else class="event-banner-card__placeholder">
        <i class="pi pi-image" />
      </div>
      <div class="event-banner-card__status-slot">
        <slot name="status">
          <span class="event-banner-card__status" :class="statusCss">{{ statusLabel }}</span>
        </slot>
      </div>
      <div class="event-banner-card__menu-slot">
        <slot name="menu" />
      </div>
      <img
        v-if="logoUrl"
        class="event-banner-card__logo"
        :src="logoUrl"
        :alt="name"
      />
    </div>
    <div class="event-banner-card__body">
      <h3>{{ name }}</h3>
      <span v-if="audienceLabel" class="event-banner-card__type">{{ audienceLabel }}</span>
      <p class="event-banner-card__meta">
        <i class="pi pi-calendar" /> {{ datesLabel }}
      </p>
      <p class="event-banner-card__meta">
        <i class="pi pi-map-marker" /> {{ placeLabel }}
      </p>
      <p v-if="description" class="event-banner-card__desc">{{ description }}</p>
      <div class="event-banner-card__stats">
        <div>
          <span>{{ cupoCaption }}</span>
          <strong>{{ cupoLabel }}</strong>
        </div>
        <div>
          <span>{{ scoreCaption }}</span>
          <strong>{{ scoreLabel }}</strong>
        </div>
      </div>
      <slot />
    </div>
  </article>
</template>

<style scoped>
.event-banner-card {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 14px;
  overflow: hidden;
  background: var(--pj-bg-elevated, #fff);
  box-shadow: 0 8px 24px color-mix(in srgb, var(--pj-navy) 8%, transparent);
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.event-banner-card__media {
  position: relative;
  aspect-ratio: 16 / 9;
  background: color-mix(in srgb, var(--pj-navy) 8%, #e2e8f0);
}

.event-banner-card__media > img:not(.event-banner-card__logo) {
  position: absolute;
  inset: 0;
  z-index: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.event-banner-card__placeholder {
  height: 100%;
  display: grid;
  place-content: center;
  color: color-mix(in srgb, var(--pj-navy) 35%, transparent);
  font-size: 1.75rem;
}

.event-banner-card__status-slot {
  position: absolute;
  top: 0.65rem;
  left: 0.65rem;
  z-index: 4;
}

.event-banner-card__menu-slot {
  position: absolute;
  top: 0.45rem;
  right: 0.45rem;
  z-index: 5;
}

.event-banner-card__status {
  display: inline-flex;
  background: #16a34a;
  color: #fff;
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
}

.event-banner-card__status.status--en-proceso {
  background: #2563eb;
}

.event-banner-card__status.status--borrador,
.event-banner-card__status.status--default {
  background: #f59e0b;
}

.event-banner-card__status.status--cerrado {
  background: #64748b;
}

.event-banner-card__status.status--cancelado {
  background: #dc2626;
}

.event-banner-card__logo {
  position: absolute;
  left: 1rem;
  bottom: -1.35rem;
  z-index: 3;
  width: 4.25rem;
  height: 4.25rem;
  object-fit: cover;
  border-radius: 0.85rem;
  border: 3px solid #fff;
  background: #fff;
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.18);
}

.event-banner-card__body {
  padding: 1.7rem 1rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
}

.event-banner-card__body h3 {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.25;
  color: var(--pj-navy);
}

.event-banner-card__type {
  align-self: flex-start;
  font-size: 0.72rem;
  font-weight: 700;
  color: #0f766e;
  background: color-mix(in srgb, #14b8a6 14%, transparent);
  border-radius: 999px;
  padding: 0.15rem 0.55rem;
}

.event-banner-card__meta {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  color: var(--pj-text-muted);
}

.event-banner-card__desc {
  margin: 0.25rem 0 0;
  font-size: 0.84rem;
  color: color-mix(in srgb, var(--pj-text) 75%, transparent);
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.event-banner-card__stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin-top: 0.55rem;
}

.event-banner-card__stats > div {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 10px;
  padding: 0.45rem 0.55rem;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.event-banner-card__stats span {
  font-size: 0.68rem;
  color: var(--pj-text-muted);
}

.event-banner-card__stats strong {
  font-size: 0.9rem;
}

.event-banner-card__body > :last-child:not(.event-banner-card__stats) {
  margin-top: 0.75rem;
  padding-top: 0.75rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
}
</style>
