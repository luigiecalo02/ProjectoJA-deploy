<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { getApiErrorMessage } from '@/services/api'
import { publicEventosService, type PublicEventoCard } from '@/services/publicEventosService'
import { resolveAssetUrl } from '@/modules/settings/assetUrl'

const { t } = useI18n()
const router = useRouter()

const loading = ref(true)
const errorMessage = ref('')
const eventos = ref<PublicEventoCard[]>([])

function money(value: number): string {
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(value)
}

function formatRange(start?: string | null, end?: string | null): string {
  if (!start) return t('publicEventos.dateTbd')
  const from = new Date(start)
  const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }
  if (!end) return from.toLocaleDateString('es-CO', opts)
  const to = new Date(end)
  return `${from.toLocaleDateString('es-CO', opts)} – ${to.toLocaleDateString('es-CO', opts)}`
}

function cover(event: PublicEventoCard): string | null {
  return resolveAssetUrl(event.banner_url || event.image_url)
}

function place(event: PublicEventoCard): string {
  return event.lugar_catalogo?.nombre || event.lugar || t('publicEventos.placeTbd')
}

onMounted(async () => {
  try {
    eventos.value = await publicEventosService.list()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error, t('publicEventos.loadError'))
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="public-events">
    <header class="public-events__head">
      <p class="kicker">{{ t('publicEventos.kicker') }}</p>
      <h1>{{ t('publicEventos.listTitle') }}</h1>
      <p>{{ t('publicEventos.listSubtitle') }}</p>
    </header>

    <Message v-if="errorMessage" severity="error" :closable="false">{{ errorMessage }}</Message>
    <p v-else-if="loading" class="pj-muted">{{ t('common.loading') }}</p>
    <p v-else-if="!eventos.length" class="empty">{{ t('publicEventos.empty') }}</p>

    <ul v-else class="public-events__grid">
      <li v-for="event in eventos" :key="event.id">
        <article class="card">
          <div class="card__cover" :class="{ 'is-empty': !cover(event) }">
            <img v-if="cover(event)" :src="cover(event)!" :alt="event.name" />
            <i v-else class="pi pi-calendar" />
          </div>
          <div class="card__body">
            <h2>{{ event.name }}</h2>
            <p class="meta">
              <span><i class="pi pi-map-marker" /> {{ place(event) }}</span>
              <span><i class="pi pi-clock" /> {{ formatRange(event.starts_at, event.ends_at) }}</span>
            </p>
            <p v-if="event.descripcion" class="desc">{{ event.descripcion }}</p>
            <div class="card__foot">
              <strong>
                {{ event.requiere_pago && event.precio > 0 ? money(event.precio) : t('publicEventos.free') }}
              </strong>
              <Button
                size="small"
                :label="t('publicEventos.enroll')"
                icon="pi pi-arrow-right"
                icon-pos="right"
                @click="router.push({ name: 'eventos.publicos.inscribir', params: { id: event.id } })"
              />
            </div>
          </div>
        </article>
      </li>
    </ul>

    <Button
      type="button"
      text
      :label="t('publicEventos.backLogin')"
      icon="pi pi-sign-in"
      @click="router.push({ name: 'login' })"
    />
  </div>
</template>

<style scoped>
.public-events { display: flex; flex-direction: column; gap: 1rem; }
.public-events__head h1 { margin: 0.15rem 0; font-size: 1.45rem; }
.public-events__head p { margin: 0; color: var(--pj-text-muted); font-size: 0.88rem; }
.kicker { font-weight: 700; color: var(--pj-navy); font-size: 0.75rem; letter-spacing: 0.04em; text-transform: uppercase; }
.empty { padding: 1.2rem; border-radius: 12px; background: var(--pj-bg-muted); color: var(--pj-text-muted); }
.public-events__grid { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.9rem; }
.card { border: 1px solid var(--pj-border); border-radius: 16px; overflow: hidden; background: var(--pj-bg-elevated); }
.card__cover { height: 140px; background: var(--pj-bg-muted); display: flex; align-items: center; justify-content: center; }
.card__cover img { width: 100%; height: 100%; object-fit: cover; }
.card__cover.is-empty { color: var(--pj-text-muted); font-size: 1.8rem; }
.card__body { display: flex; flex-direction: column; gap: 0.5rem; padding: 0.9rem 1rem 1rem; }
.card__body h2 { margin: 0; font-size: 1.05rem; }
.meta { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.8rem; color: var(--pj-text-muted); }
.meta i { margin-right: 0.3rem; }
.desc { margin: 0; font-size: 0.85rem; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.card__foot { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; margin-top: 0.25rem; }
</style>
