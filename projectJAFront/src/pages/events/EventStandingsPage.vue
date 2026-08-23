<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import PageLoader from '@/components/PageLoader.vue'
import EventSearchPanel from '@/components/events/EventSearchPanel.vue'
import { eventsService } from '@/services/eventsService'
import { resolveAssetUrl, toCssImageUrl } from '@/modules/settings/assetUrl'
import { extractBannerHeroVars } from '@/utils/dominantColor'
import { getApiErrorMessage } from '@/services/api'
import type { EventStandings, EventStandingsSort } from '@/modules/events/types'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()

const loading = ref(true)
const data = ref<EventStandings | null>(null)
const selectedScopeId = ref<number | null>(null)
const sort = ref<EventStandingsSort>('puesto')
const search = ref('')
const bootstrapped = ref(false)

const eventId = computed(() => Number(route.params.id))
const bannerUrl = computed(() => resolveAssetUrl(data.value?.evento.banner_url))
const logoUrl = computed(() => resolveAssetUrl(data.value?.evento.image_url))
const heroCoverUrl = computed(() => bannerUrl.value || logoUrl.value)
const showEventLogo = computed(() => Boolean(logoUrl.value && bannerUrl.value))
const heroTheme = ref<Record<string, string>>({})
let heroThemeSequence = 0
const heroStyle = computed(() => {
  const url = heroCoverUrl.value
  if (!url) return undefined
  return {
    '--hero-image': toCssImageUrl(url),
    ...heroTheme.value,
  }
})

watch(
  heroCoverUrl,
  async (url) => {
    heroTheme.value = {}
    if (!url) return
    const sequence = ++heroThemeSequence
    try {
      const vars = await extractBannerHeroVars(url)
      if (sequence !== heroThemeSequence) return
      heroTheme.value = vars
    } catch {
      if (sequence !== heroThemeSequence) return
      heroTheme.value = {}
    }
  },
  { immediate: true },
)

const sortOptions = computed(() => [
  { value: 'puesto' as const, label: t('events.standingsSortPuesto') },
  { value: 'puntaje' as const, label: t('events.standingsSortScore') },
  { value: 'nombre' as const, label: t('events.standingsSortName') },
  { value: 'distrito' as const, label: t('events.standingsSortDistrict') },
])

const scopeOptions = computed(() =>
  (data.value?.subeventos ?? []).map((s) => ({
    id: s.id,
    label: s.label || s.name,
  })),
)

function podiumClass(puesto: number): string {
  if (puesto === 1) return 'is-gold'
  if (puesto === 2) return 'is-silver'
  if (puesto === 3) return 'is-bronze'
  return ''
}

function clubLocationLabel(row: { distrito?: string | null; iglesia?: string | null }): string {
  const distrito = (row.distrito || '').trim()
  const iglesia = (row.iglesia || '').trim()
  const hasDistrito = distrito !== '' && distrito !== '—'
  if (hasDistrito && iglesia) return `${distrito} - ${iglesia}`
  if (hasDistrito) return distrito
  return iglesia
}

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await eventsService.standings(eventId.value, {
      subevento_id: selectedScopeId.value,
      sort: sort.value,
      q: search.value.trim() || undefined,
    })
    if (!bootstrapped.value && data.value.alcance?.evento_id) {
      selectedScopeId.value = data.value.alcance.evento_id
      bootstrapped.value = true
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    loading.value = false
  }
}

function onScopeChange(id: number | null): void {
  selectedScopeId.value = id
  if (!bootstrapped.value) return
  void load()
}

function onSortChange(value: EventStandingsSort): void {
  sort.value = value
  if (!bootstrapped.value) return
  void load()
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  if (!bootstrapped.value) return
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    void load()
  }, 300)
})

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="standings-page">
    <header
      class="pj-page__header standings-hero"
      :class="{
        'has-cover': Boolean(heroCoverUrl),
        'has-logo': showEventLogo,
      }"
      :style="heroStyle"
    >
      <div class="standings-hero__intro">
        <img
          v-if="showEventLogo && logoUrl"
          class="standings-hero__logo"
          :src="logoUrl"
          :alt="data?.evento.name || t('events.standingsTitle')"
        />
        <div class="standings-hero__copy">
          <p class="standings-kicker">{{ t('events.standingsKicker') }}</p>
          <h1 class="pj-page__title standings-title">
            {{ data?.evento.name || t('events.standingsTitle') }}
          </h1>
          <p class="pj-muted">
            {{ t('events.standingsSubtitle') }}
            <template v-if="data?.alcance">
              · {{ data.alcance.nombre }}
            </template>
          </p>
        </div>
      </div>
      <div v-if="data" class="standings-summary">
        <div>
          <strong>{{ data.totales.clubes }}</strong>
          <span>{{ t('events.standingsClubs') }}</span>
        </div>
        <div>
          <strong>{{ data.totales.con_puntaje }}</strong>
          <span>{{ t('events.standingsWithScore') }}</span>
        </div>
        <div>
          <strong>{{ data.totales.puntaje_maximo_alcance ?? '—' }}</strong>
          <span>{{ t('events.standingsMaxPts') }}</span>
        </div>
      </div>
    </header>

    <PageLoader v-if="loading && !data" />

    <template v-else-if="data">
      <section class="pj-toolbar standings-toolbar">
        <div class="field">
          <label>{{ t('events.standingsScope') }}</label>
          <Select
            :model-value="selectedScopeId"
            :options="scopeOptions"
            option-label="label"
            option-value="id"
            class="w-full"
            @update:model-value="onScopeChange"
          />
        </div>
        <div class="field">
          <label>{{ t('events.standingsSort') }}</label>
          <Select
            :model-value="sort"
            :options="sortOptions"
            option-label="label"
            option-value="value"
            class="w-full"
            @update:model-value="onSortChange"
          />
        </div>
      </section>

      <EventSearchPanel
        v-model="search"
        input-id="standings-search"
        icon="pi pi-users"
        :label="t('events.standingsSearchLabel')"
        :placeholder="t('events.standingsSearch')"
        :hint="t('segurosConsulta.liveSearchHint')"
      />

      <section class="pj-panel standings-panel">
        <DataTable
          :value="data.standings"
          data-key="organizacion_id"
          striped-rows
          class="standings-table"
          :empty-message="t('events.standingsEmpty')"
        >
          <Column :header="t('events.standingsPlace')" style="width: 5rem">
            <template #body="{ data: row }">
              <span class="place" :class="podiumClass(row.puesto)">{{ row.puesto }}</span>
            </template>
          </Column>
          <Column :header="t('events.standingsClub')">
            <template #body="{ data: row }">
              <div class="club-cell" :title="row.nombre">
                <span v-if="row.logo_url" class="club-cell__logo">
                  <img :src="row.logo_url" :alt="row.nombre" />
                </span>
                <span v-else class="club-cell__fallback" :title="row.nombre">
                  <i class="pi pi-flag" />
                </span>
                <div class="club-cell__meta">
                  <strong>{{ row.nombre }}</strong>
                  <small v-if="clubLocationLabel(row)" class="pj-muted">
                    {{ clubLocationLabel(row) }}
                  </small>
                </div>
              </div>
            </template>
          </Column>
          <Column v-if="data.alcance.es_root" :header="t('events.standingsInscription')">
            <template #body="{ data: row }">
              {{ row.puntos_inscripcion ?? '—' }}
            </template>
          </Column>
          <Column :header="t('events.standingsSubPts')">
            <template #body="{ data: row }">
              {{ row.puntos_subeventos }}
            </template>
          </Column>
          <Column :header="t('events.standingsTotal')">
            <template #body="{ data: row }">
              <strong class="total">{{ row.puntos_total }}</strong>
              <small v-if="row.puntos_maximo != null" class="pj-muted">
                / {{ row.puntos_maximo }}
              </small>
            </template>
          </Column>
          <Column :header="t('events.standingsPct')" style="width: 5.5rem">
            <template #body="{ data: row }">
              {{ row.porcentaje != null ? `${row.porcentaje}%` : '—' }}
            </template>
          </Column>
        </DataTable>
      </section>
    </template>
  </div>
</template>

<style scoped>
.standings-page {
  display: grid;
  gap: 1rem;
}

.standings-hero {
  display: flex;
  justify-content: space-between;
  gap: 1.25rem;
  align-items: flex-end;
  flex-wrap: wrap;
  padding: 1.25rem 1.35rem;
  border-radius: 16px;
  overflow: visible;
  isolation: isolate;
  background:
    linear-gradient(135deg, color-mix(in srgb, #0f766e 18%, transparent), transparent 55%),
    linear-gradient(180deg, color-mix(in srgb, #f8fafc 88%, #ecfdf5), #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.standings-hero.has-cover {
  min-height: 11rem;
  color: var(--hero-text, #fff);
  background-image:
    var(--hero-overlay, linear-gradient(180deg, rgba(7, 18, 42, 0.28) 0%, rgba(7, 18, 42, 0.78) 100%)),
    var(--hero-image);
  background-size: cover;
  background-position: center;
  border-color: transparent;
}

.standings-hero.has-cover .pj-muted,
.standings-hero.has-cover .standings-kicker {
  color: var(--hero-muted, rgba(255, 255, 255, 0.86));
}

.standings-hero.has-cover .standings-summary > div {
  background: var(--hero-chip-bg, color-mix(in srgb, #07122a 45%, transparent));
  border-color: var(--hero-chip-border, rgba(255, 255, 255, 0.18));
  color: var(--hero-chip-text, #fff);
}

.standings-hero.has-cover .standings-summary span {
  color: var(--hero-chip-muted, rgba(255, 255, 255, 0.78));
}

.standings-hero.has-cover .standings-title {
  color: var(--hero-text, #fff);
  text-shadow: 0 1px 12px color-mix(in srgb, var(--hero-chip-bg, rgba(15, 23, 42, 0.5)) 70%, transparent);
}

.standings-hero__intro {
  display: flex;
  align-items: center;
  gap: 0.95rem;
  min-width: 0;
}

.standings-hero__logo {
  width: 4.5rem;
  height: 4.5rem;
  flex: 0 0 auto;
  object-fit: cover;
  border-radius: 0.9rem;
  border: 3px solid #fff;
  background: #fff;
  box-shadow: 0 10px 22px rgba(15, 23, 42, 0.28);
}

.standings-hero.has-cover.has-logo {
  position: relative;
  overflow: visible;
  margin-bottom: 2.4rem;
}

.standings-hero.has-cover.has-logo .standings-hero__intro {
  align-items: flex-end;
}

.standings-hero.has-cover.has-logo .standings-hero__copy {
  padding-left: calc(4.5rem + 0.95rem);
}

.standings-hero.has-cover.has-logo .standings-hero__logo {
  position: absolute;
  left: 1.35rem;
  bottom: 0;
  z-index: 2;
  margin: 0;
  transform: translateY(50%);
}

.standings-hero.has-cover.has-logo .standings-summary {
  align-self: flex-start;
  margin-left: auto;
}

.standings-kicker {
  margin: 0 0 0.15rem;
  font-size: 0.78rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #0f766e;
  font-weight: 700;
}

.standings-title {
  margin: 0;
  font-family: var(--pj-font-display), Georgia, serif;
  font-size: clamp(1.6rem, 2.4vw, 2.2rem);
  line-height: 1.15;
}

.standings-summary {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  margin-left: auto;
  justify-content: flex-end;
  width: auto;
}

.standings-summary > div {
  min-width: 6.5rem;
  display: grid;
  gap: 0.15rem;
  padding: 0.65rem 0.85rem;
  border-radius: 12px;
  background: color-mix(in srgb, #fff 80%, #ecfdf5);
  border: 1px solid color-mix(in srgb, #0f766e 18%, transparent);
}

.standings-summary strong {
  font-size: 1.25rem;
}

.standings-summary span {
  font-size: 0.75rem;
  color: var(--pj-text-muted, #64748b);
}

@media (max-width: 899px) {
  .standings-hero.pj-page__header {
    align-items: stretch;
  }

  .standings-summary {
    align-self: flex-end;
  }
}

.standings-toolbar {
  display: grid;
  grid-template-columns: minmax(14rem, 1.4fr) minmax(10rem, 0.8fr);
  gap: 0.85rem;
  align-items: end;
}

.standings-toolbar .field {
  display: grid;
  gap: 0.35rem;
}

.standings-toolbar label {
  font-size: 0.78rem;
  font-weight: 650;
  color: var(--pj-text-muted, #64748b);
}

.standings-panel {
  padding: 0.35rem;
  overflow: auto;
}

.club-cell {
  display: flex;
  align-items: center;
  gap: 0.7rem;
  min-width: 0;
}

.club-cell__logo,
.club-cell__fallback {
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 10px;
  overflow: hidden;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #0f766e 12%, transparent);
  color: #0f766e;
}

.club-cell__logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.club-cell strong {
  display: block;
  line-height: 1.2;
}

.club-cell small {
  display: block;
  margin-top: 0.1rem;
}

.place {
  display: inline-grid;
  place-items: center;
  min-width: 1.85rem;
  height: 1.85rem;
  border-radius: 999px;
  font-weight: 800;
  background: color-mix(in srgb, #94a3b8 16%, transparent);
  color: #475569;
}

.place.is-gold {
  background: color-mix(in srgb, #eab308 28%, transparent);
  color: #854d0e;
}

.place.is-silver {
  background: color-mix(in srgb, #94a3b8 28%, transparent);
  color: #334155;
}

.place.is-bronze {
  background: color-mix(in srgb, #d97706 24%, transparent);
  color: #9a3412;
}

.total {
  font-variant-numeric: tabular-nums;
}

@media (max-width: 900px) {
  .standings-toolbar {
    grid-template-columns: 1fr;
  }

  .club-cell__meta {
    display: none;
  }
}
</style>
