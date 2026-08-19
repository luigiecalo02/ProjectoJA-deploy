<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import PageLoader from '@/components/PageLoader.vue'
import EventSearchPanel from '@/components/events/EventSearchPanel.vue'
import { eventsService } from '@/services/eventsService'
import { resolveAssetUrl, toCssImageUrl } from '@/modules/settings/assetUrl'
import { getApiErrorMessage } from '@/services/api'
import type {
  JudgeEvaluacionClub,
  JudgeEvaluacionDetalle,
  JudgeEvaluacionEstado,
  JudgeEvaluaciones,
} from '@/modules/events/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const loading = ref(true)
const data = ref<JudgeEvaluaciones | null>(null)
const search = ref('')
const estado = ref<JudgeEvaluacionEstado | ''>('')
const distrito = ref<string | null>(null)
const subeventoId = ref<number | null>(null)
const selectedOrgId = ref<number | null>(null)
const bootstrapped = ref(false)

const eventId = computed(() => Number(route.params.id))
const heroStyle = computed(() => {
  const url = resolveAssetUrl(data.value?.evento.image_url)
  return url ? { '--hero-image': toCssImageUrl(url) } : undefined
})

const estadoOptions = computed(() => [
  { value: '', label: t('events.judgeFilterAll') },
  { value: 'completado' as const, label: t('events.judgeEvalStatusDone') },
  { value: 'pendiente' as const, label: t('events.judgeEvalStatusPending') },
  { value: 'sin_evidencia' as const, label: t('events.judgeEvalStatusNoEvidence') },
])

const distritoOptions = computed(() =>
  (data.value?.filtros.distritos ?? []).map((d) => ({ value: d, label: d })),
)

const subeventoOptions = computed(() =>
  (data.value?.filtros.subeventos ?? []).map((s) => ({ value: s.id, label: s.name })),
)

const detalle = computed<JudgeEvaluacionDetalle | null>(() => data.value?.detalle ?? null)

const pageRows = ref(10)
const first = ref(0)

const pagedHint = computed(() => {
  const total = data.value?.clubes.length ?? 0
  if (!total) return ''
  const from = first.value + 1
  const to = Math.min(first.value + pageRows.value, total)
  return t('events.judgeEvalShowing', { from, to, total })
})

function estadoLabel(value: JudgeEvaluacionEstado): string {
  if (value === 'completado') return t('events.judgeEvalStatusDone')
  if (value === 'pendiente') return t('events.judgeEvalStatusPending')
  return t('events.judgeEvalStatusNoEvidence')
}

function formatDate(value?: string | null): string {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleString('es-ES', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function clubLocation(row: JudgeEvaluacionClub | JudgeEvaluacionDetalle): string {
  const dist = (row.distrito || '').trim()
  const igl = (row.iglesia || '').trim()
  if (dist && dist !== '—' && igl) return `${dist} · ${igl}`
  if (igl) return igl
  if (dist && dist !== '—') return dist
  return ''
}

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await eventsService.judgeEvaluaciones(eventId.value, {
      q: search.value.trim() || undefined,
      estado: estado.value || undefined,
      distrito: distrito.value || undefined,
      subevento_id: subeventoId.value,
      organizacion_id: selectedOrgId.value,
    })
    bootstrapped.value = true
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

function selectClub(row: JudgeEvaluacionClub): void {
  selectedOrgId.value = row.organizacion_id
  void load()
}

function clearFilters(): void {
  search.value = ''
  estado.value = ''
  distrito.value = null
  subeventoId.value = null
  first.value = 0
  void load()
}

function goJudgeFull(): void {
  if (!selectedOrgId.value) return
  router.push({
    name: 'events.judge',
    params: { id: eventId.value },
    query: { organizacion_id: String(selectedOrgId.value) },
  })
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  if (!bootstrapped.value) return
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    first.value = 0
    void load()
  }, 300)
})

watch([estado, distrito, subeventoId], () => {
  if (!bootstrapped.value) return
  first.value = 0
  void load()
})

onMounted(() => {
  const qOrg = route.query.organizacion_id
  if (qOrg != null && qOrg !== '') {
    selectedOrgId.value = Number(qOrg)
  }
  void load()
})
</script>

<template>
  <div class="eval-page" :class="{ 'has-detail': !!detalle }">
    <header
      class="eval-hero"
      :class="{ 'has-cover': Boolean(data?.evento.image_url) }"
      :style="heroStyle"
    >
      <div>
        <Button
          type="button"
          text
          icon="pi pi-arrow-left"
          :label="t('events.judgeEvalBack')"
          class="eval-back"
          @click="router.push({ name: 'events.judge', params: { id: eventId } })"
        />
        <p class="eval-kicker">{{ t('events.judgeEvalKicker') }}</p>
        <h1>{{ data?.evento.name || t('events.judgeEvalTitle') }}</h1>
        <p class="pj-muted">{{ t('events.judgeEvalSubtitle') }}</p>
      </div>
    </header>

    <PageLoader v-if="loading && !data" />

    <template v-else-if="data">
      <section class="eval-stats">
        <div class="stat-card">
          <i class="pi pi-users" />
          <div>
            <strong>{{ data.totales.evaluados }}</strong>
            <span>{{ t('events.judgeEvalStatAll') }}</span>
          </div>
        </div>
        <div class="stat-card is-done">
          <i class="pi pi-check-circle" />
          <div>
            <strong>{{ data.totales.completos }}</strong>
            <span>{{ t('events.judgeEvalStatDone') }}</span>
          </div>
        </div>
        <div class="stat-card is-pending">
          <i class="pi pi-clock" />
          <div>
            <strong>{{ data.totales.pendientes }}</strong>
            <span>{{ t('events.judgeEvalStatPending') }}</span>
          </div>
        </div>
        <div class="stat-card is-avg">
          <i class="pi pi-chart-bar" />
          <div>
            <strong>
              {{ data.totales.promedio_pct != null ? `${data.totales.promedio_pct}%` : '—' }}
            </strong>
            <span>{{ t('events.judgeEvalStatAvg') }}</span>
          </div>
        </div>
      </section>

      <section class="pj-toolbar eval-toolbar">
        <div class="field field--search">
          <EventSearchPanel
            v-model="search"
            input-id="judge-eval-search"
            icon="pi pi-users"
            :label="t('events.standingsSearchLabel')"
            :placeholder="t('events.judgeEvalSearch')"
            :hint="t('segurosConsulta.liveSearchHint')"
          />
        </div>
        <div class="field">
          <Select
            v-model="estado"
            :options="estadoOptions"
            option-label="label"
            option-value="value"
            class="w-full"
            :placeholder="t('events.judgeEvalEstado')"
          />
        </div>
        <div class="field">
          <Select
            v-model="distrito"
            :options="distritoOptions"
            option-label="label"
            option-value="value"
            show-clear
            class="w-full"
            :placeholder="t('events.judgeEvalDistrito')"
          />
        </div>
        <div class="field">
          <Select
            v-model="subeventoId"
            :options="subeventoOptions"
            option-label="label"
            option-value="value"
            show-clear
            class="w-full"
            :placeholder="t('events.judgeEvalSubevento')"
          />
        </div>
        <Button
          type="button"
          outlined
          icon="pi pi-filter-slash"
          :label="t('events.judgeEvalClearFilters')"
          @click="clearFilters"
        />
      </section>

      <div class="eval-layout">
        <section class="pj-panel eval-table-panel">
          <DataTable
            :value="data.clubes"
            data-key="organizacion_id"
            striped-rows
            paginator
            :rows="pageRows"
            v-model:first="first"
            :rows-per-page-options="[7, 10, 20]"
            selection-mode="single"
            :selection="data.clubes.find((c) => c.organizacion_id === selectedOrgId) || null"
            class="eval-table"
            :empty-message="t('events.judgeEvalEmpty')"
            @row-select="(e) => selectClub(e.data as JudgeEvaluacionClub)"
          >
            <Column header="#" style="width: 3.5rem">
              <template #body="{ index }">
                {{ first + index + 1 }}
              </template>
            </Column>
            <Column :header="t('events.judgeEvalColClub')">
              <template #body="{ data: row }">
                <div class="club-cell">
                  <span v-if="row.logo_url" class="club-cell__logo">
                    <img :src="row.logo_url" :alt="row.nombre" />
                  </span>
                  <span v-else class="club-cell__fallback"><i class="pi pi-flag" /></span>
                  <div>
                    <strong>{{ row.nombre }}</strong>
                    <small v-if="row.iglesia" class="pj-muted">{{ row.iglesia }}</small>
                  </div>
                </div>
              </template>
            </Column>
            <Column field="distrito" :header="t('events.judgeEvalColDistrict')" />
            <Column :header="t('events.judgeEvalColSub')">
              <template #body="{ data: row }">
                {{ row.subevento_evaluado || '—' }}
              </template>
            </Column>
            <Column :header="t('events.judgeEvalColScore')">
              <template #body="{ data: row }">
                <template v-if="row.eventos_evaluados > 0">
                  <strong>{{ row.puntaje_otorgado }}</strong>
                  <span v-if="row.puntaje_maximo != null" class="pj-muted">
                    / {{ row.puntaje_maximo }}
                  </span>
                  <small v-if="row.porcentaje != null" class="score-pct">{{ row.porcentaje }}%</small>
                </template>
                <span v-else class="pj-muted">—</span>
              </template>
            </Column>
            <Column :header="t('events.judgeEvalColStatus')">
              <template #body="{ data: row }">
                <span class="status-pill" :class="`is-${row.estado}`">
                  {{ estadoLabel(row.estado) }}
                </span>
              </template>
            </Column>
            <Column :header="t('events.judgeEvalColDate')">
              <template #body="{ data: row }">
                {{ formatDate(row.updated_at) }}
              </template>
            </Column>
            <Column style="width: 2.5rem">
              <template #body>
                <i class="pi pi-chevron-right row-go" />
              </template>
            </Column>
          </DataTable>
          <p v-if="pagedHint" class="eval-paging-hint pj-muted">{{ pagedHint }}</p>
        </section>

        <aside class="pj-panel eval-detail">
          <template v-if="detalle">
            <header class="detail-head">
              <span v-if="detalle.logo_url" class="detail-logo">
                <img :src="detalle.logo_url" :alt="detalle.nombre" />
              </span>
              <span v-else class="detail-logo is-fallback"><i class="pi pi-flag" /></span>
              <div>
                <h2>{{ detalle.nombre }}</h2>
                <p class="pj-muted">{{ clubLocation(detalle) }}</p>
              </div>
            </header>

            <div class="detail-score">
              <div class="detail-score__top">
                <strong>
                  {{ detalle.puntaje_otorgado }}
                  <small v-if="detalle.puntaje_maximo != null">
                    / {{ detalle.puntaje_maximo }} pts
                  </small>
                </strong>
                <span v-if="detalle.porcentaje != null">
                  {{ t('events.judgeEvalPctOfTotal', { pct: detalle.porcentaje }) }}
                </span>
              </div>
              <div class="detail-score__bar">
                <span :style="{ width: `${Math.min(100, detalle.porcentaje || 0)}%` }" />
              </div>
            </div>

            <section class="detail-section">
              <h3>{{ t('events.judgeEvalBreakdown') }}</h3>
              <ul v-if="detalle.desglose.length" class="breakdown">
                <li v-for="item in detalle.desglose" :key="item.evento_id">
                  <div class="breakdown__main">
                    <strong>{{ item.name }}</strong>
                    <span class="status-pill is-sm" :class="`is-${item.estado}`">
                      {{ estadoLabel(item.estado) }}
                    </span>
                  </div>
                  <div class="breakdown__score">
                    <template v-if="item.puntaje_obtenido != null">
                      {{ item.puntaje_obtenido }}
                      <small v-if="item.puntaje_maximo != null">/ {{ item.puntaje_maximo }}</small>
                      <em v-if="item.porcentaje != null">{{ item.porcentaje }}%</em>
                    </template>
                    <span v-else class="pj-muted">—</span>
                  </div>
                </li>
              </ul>
              <p v-else class="pj-muted">{{ t('events.judgeEvalEmpty') }}</p>
            </section>

            <section
              v-if="detalle.desglose.some((d) => d.evidencias.length)"
              class="detail-section"
            >
              <h3>{{ t('events.judgeEvalEvidence') }}</h3>
              <ul class="evidence-list">
                <template v-for="item in detalle.desglose" :key="`ev-${item.evento_id}`">
                  <li v-for="ev in item.evidencias" :key="ev.id">
                    <i
                      :class="{
                        'pi pi-link': ev.tipo === 'link',
                        'pi pi-file-pdf': ev.tipo === 'pdf',
                        'pi pi-image': ev.tipo === 'imagen',
                        'pi pi-volume-up': ev.tipo === 'audio',
                        'pi pi-video': ev.tipo === 'video',
                        'pi pi-paperclip': !['link', 'pdf', 'imagen', 'audio', 'video'].includes(ev.tipo),
                      }"
                    />
                    <div>
                      <strong>{{ ev.titulo || item.name }}</strong>
                      <small class="pj-muted">{{ item.name }} · {{ ev.tipo }}</small>
                    </div>
                    <a
                      v-if="ev.url"
                      :href="ev.url"
                      target="_blank"
                      rel="noopener"
                      class="evidence-view"
                    >
                      Ver
                    </a>
                  </li>
                </template>
              </ul>
            </section>

            <section
              v-if="detalle.desglose.some((d) => d.observaciones)"
              class="detail-section"
            >
              <h3>{{ t('events.judgeEvalObs') }}</h3>
              <div
                v-for="item in detalle.desglose.filter((d) => d.observaciones)"
                :key="`obs-${item.evento_id}`"
                class="obs-block"
              >
                <strong>{{ item.name }}</strong>
                <p>{{ item.observaciones }}</p>
              </div>
            </section>

            <p v-if="detalle.evaluado_por" class="detail-meta pj-muted">
              {{ t('events.judgeEvalBy') }}: {{ detalle.evaluado_por }}
            </p>

            <div class="detail-actions">
              <Button
                type="button"
                icon="pi pi-eye"
                :label="t('events.judgeEvalOpenFull')"
                class="w-full"
                @click="goJudgeFull"
              />
            </div>
          </template>
          <div v-else class="detail-empty">
            <i class="pi pi-info-circle" />
            <p>{{ t('events.judgeEvalSelectHint') }}</p>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
.eval-page {
  display: grid;
  gap: 1rem;
}

.eval-hero {
  padding: 1.15rem 1.3rem;
  border-radius: 16px;
  overflow: hidden;
  isolation: isolate;
  background:
    linear-gradient(135deg, color-mix(in srgb, #0f766e 16%, transparent), transparent 55%),
    linear-gradient(180deg, #f8fafc, #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.eval-hero.has-cover {
  color: #fff;
  background-image:
    linear-gradient(180deg, rgba(7, 18, 42, 0.28) 0%, rgba(7, 18, 42, 0.78) 100%),
    var(--hero-image);
  background-size: cover;
  background-position: center;
  border-color: transparent;
}

.eval-hero.has-cover .pj-muted,
.eval-hero.has-cover .eval-kicker {
  color: rgba(255, 255, 255, 0.86);
}

.eval-back {
  margin-left: -0.5rem;
}

.eval-kicker {
  margin: 0.35rem 0 0.1rem;
  font-size: 0.75rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  font-weight: 700;
  color: #0f766e;
}

.eval-hero h1 {
  margin: 0;
  font-family: var(--pj-font-display), Georgia, serif;
  font-size: clamp(1.5rem, 2.2vw, 2rem);
  line-height: 1.15;
}

.eval-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.75rem;
}

.stat-card {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  padding: 0.85rem 1rem;
  border-radius: 14px;
  background: #fff;
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.stat-card i {
  width: 2.2rem;
  height: 2.2rem;
  border-radius: 10px;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #0f766e 12%, transparent);
  color: #0f766e;
}

.stat-card.is-done i {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.stat-card.is-pending i {
  background: color-mix(in srgb, #ca8a04 16%, transparent);
  color: #a16207;
}

.stat-card.is-avg i {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.stat-card strong {
  display: block;
  font-size: 1.25rem;
  line-height: 1.1;
}

.stat-card span {
  font-size: 0.75rem;
  color: var(--pj-text-muted, #64748b);
}

.eval-toolbar {
  display: grid;
  grid-template-columns: minmax(12rem, 1.4fr) repeat(3, minmax(8rem, 0.9fr)) auto;
  gap: 0.65rem;
  align-items: center;
}

.eval-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(18rem, 22rem);
  gap: 1rem;
  align-items: start;
}

.eval-page:not(.has-detail) .eval-layout {
  grid-template-columns: 1fr;
}

.eval-page:not(.has-detail) .eval-detail {
  display: none;
}

.eval-table-panel {
  padding: 0.35rem;
  overflow: auto;
}

.eval-paging-hint {
  margin: 0.5rem 0.65rem 0.35rem;
  font-size: 0.78rem;
}

.club-cell {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}

.club-cell__logo,
.club-cell__fallback {
  width: 2.1rem;
  height: 2.1rem;
  border-radius: 10px;
  overflow: hidden;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #0f766e 12%, transparent);
  color: #0f766e;
  flex-shrink: 0;
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

.score-pct {
  display: block;
  color: #0f766e;
  font-weight: 700;
  font-size: 0.75rem;
}

.status-pill {
  display: inline-flex;
  padding: 0.18rem 0.55rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 700;
  white-space: nowrap;
}

.status-pill.is-sm {
  font-size: 0.65rem;
  padding: 0.1rem 0.4rem;
}

.status-pill.is-completado {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.status-pill.is-pendiente {
  background: color-mix(in srgb, #ca8a04 16%, transparent);
  color: #a16207;
}

.status-pill.is-sin_evidencia {
  background: color-mix(in srgb, #94a3b8 16%, transparent);
  color: #475569;
}

.row-go {
  color: var(--pj-text-muted, #94a3b8);
}

.eval-detail {
  padding: 1rem;
  position: sticky;
  top: 0.75rem;
  display: grid;
  gap: 0.9rem;
}

.detail-head {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.detail-logo {
  width: 3rem;
  height: 3rem;
  border-radius: 12px;
  overflow: hidden;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #0f766e 12%, transparent);
  color: #0f766e;
}

.detail-logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.detail-head h2 {
  margin: 0;
  font-size: 1.05rem;
  line-height: 1.2;
}

.detail-head p {
  margin: 0.15rem 0 0;
  font-size: 0.8rem;
}

.detail-score {
  padding: 0.85rem;
  border-radius: 12px;
  background: color-mix(in srgb, #0f766e 8%, #fff);
  border: 1px solid color-mix(in srgb, #0f766e 18%, transparent);
}

.detail-score__top {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
  align-items: baseline;
  margin-bottom: 0.55rem;
}

.detail-score__top strong {
  font-size: 1.15rem;
}

.detail-score__top span {
  font-size: 0.75rem;
  color: #0f766e;
  font-weight: 650;
}

.detail-score__bar {
  height: 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, #0f766e 12%, transparent);
  overflow: hidden;
}

.detail-score__bar span {
  display: block;
  height: 100%;
  background: #0f766e;
  border-radius: inherit;
}

.detail-section h3 {
  margin: 0 0 0.55rem;
  font-size: 0.82rem;
  color: #334155;
}

.breakdown,
.evidence-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.breakdown li {
  display: flex;
  justify-content: space-between;
  gap: 0.65rem;
  padding: 0.55rem 0.6rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  background: #fff;
}

.breakdown__main {
  min-width: 0;
  display: grid;
  gap: 0.25rem;
}

.breakdown__main strong {
  font-size: 0.84rem;
}

.breakdown__score {
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  text-align: right;
}

.breakdown__score em {
  display: block;
  font-style: normal;
  font-size: 0.72rem;
  color: #0f766e;
}

.evidence-list li {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 0.5rem;
  align-items: center;
  padding: 0.45rem 0.5rem;
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-bg, #f8fafc) 70%, #fff);
}

.evidence-list i {
  color: #0f766e;
}

.evidence-list strong {
  display: block;
  font-size: 0.8rem;
}

.evidence-list small {
  display: block;
  font-size: 0.7rem;
}

.evidence-view {
  font-size: 0.78rem;
  font-weight: 700;
  color: #0f766e;
  text-decoration: none;
}

.obs-block {
  padding: 0.65rem 0.75rem;
  border-radius: 10px;
  background: color-mix(in srgb, #2563eb 6%, #fff);
  border: 1px solid color-mix(in srgb, #2563eb 14%, transparent);
  margin-bottom: 0.45rem;
}

.obs-block strong {
  display: block;
  font-size: 0.75rem;
  margin-bottom: 0.25rem;
  color: #334155;
}

.obs-block p {
  margin: 0;
  font-size: 0.86rem;
  white-space: pre-wrap;
}

.detail-meta {
  margin: 0;
  font-size: 0.78rem;
}

.detail-actions {
  margin-top: 0.25rem;
}

.detail-empty {
  display: grid;
  place-items: center;
  gap: 0.5rem;
  min-height: 14rem;
  text-align: center;
  color: var(--pj-text-muted, #64748b);
}

.detail-empty i {
  font-size: 1.5rem;
}

.w-full {
  width: 100%;
}

@media (max-width: 1100px) {
  .eval-layout {
    grid-template-columns: 1fr;
  }

  .eval-detail {
    position: static;
  }

  .eval-page:not(.has-detail) .eval-detail {
    display: none;
  }

  .eval-page.has-detail .eval-detail {
    display: grid;
  }
}

@media (max-width: 900px) {
  .eval-stats {
    grid-template-columns: 1fr 1fr;
  }

  .eval-toolbar {
    grid-template-columns: 1fr;
  }
}
</style>
