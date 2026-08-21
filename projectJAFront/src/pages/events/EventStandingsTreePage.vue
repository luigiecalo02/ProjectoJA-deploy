<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Select from 'primevue/select'
import PageLoader from '@/components/PageLoader.vue'
import EventSearchPanel from '@/components/events/EventSearchPanel.vue'
import { eventsService } from '@/services/eventsService'
import { resolveAssetUrl, toCssImageUrl } from '@/modules/settings/assetUrl'
import { getApiErrorMessage } from '@/services/api'
import type {
  EventStandingsSort,
  EventStandingsTree,
  EventStandingsTreeNode,
} from '@/modules/events/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const loading = ref(true)
const data = ref<EventStandingsTree | null>(null)
const sort = ref<EventStandingsSort>('puesto')
const search = ref('')
const bootstrapped = ref(false)
const expanded = ref<Set<number>>(new Set())

const eventId = computed(() => Number(route.params.id))
const bannerUrl = computed(() => resolveAssetUrl(data.value?.evento.banner_url))
const logoUrl = computed(() => resolveAssetUrl(data.value?.evento.image_url))
const heroCoverUrl = computed(() => bannerUrl.value || logoUrl.value)
const showEventLogo = computed(() => Boolean(logoUrl.value && bannerUrl.value))
const heroStyle = computed(() => {
  const url = heroCoverUrl.value
  return url ? { '--hero-image': toCssImageUrl(url) } : undefined
})

const sortOptions = computed(() => [
  { value: 'puesto' as const, label: t('events.standingsSortPuesto') },
  { value: 'puntaje' as const, label: t('events.standingsSortScore') },
  { value: 'nombre' as const, label: t('events.standingsSortName') },
  { value: 'distrito' as const, label: t('events.standingsSortDistrict') },
])

type HeaderCell = {
  key: string
  label: string
  colspan: number
  rowspan: number
  nodeId: number
  expandable: boolean
  expanded: boolean
  isTotal?: boolean
}

type LeafCol = {
  key: string
  nodeId: number
  label: string
  isTotal: boolean
}

function leafColumnCount(node: EventStandingsTreeNode, exp: Set<number>): number {
  if (!node.has_children || !exp.has(node.id)) return 1
  return 1 + node.children.reduce((acc, c) => acc + leafColumnCount(c, exp), 0)
}

function treeDepth(node: EventStandingsTreeNode, exp: Set<number>): number {
  if (!node.has_children || !exp.has(node.id)) return 1
  const childMax = Math.max(1, ...node.children.map((c) => treeDepth(c, exp)))
  return 1 + childMax
}

function collectLeaves(node: EventStandingsTreeNode, exp: Set<number>): LeafCol[] {
  if (!node.has_children || !exp.has(node.id)) {
    return [{ key: `n-${node.id}`, nodeId: node.id, label: node.name, isTotal: false }]
  }
  return [
    { key: `t-${node.id}`, nodeId: node.id, label: t('events.standingsTotal'), isTotal: true },
    ...node.children.flatMap((c) => collectLeaves(c, exp)),
  ]
}

function buildHeaderRows(
  root: EventStandingsTreeNode,
  exp: Set<number>,
): { rows: HeaderCell[][]; leaves: LeafCol[] } {
  const leaves = collectLeaves(root, exp)
  const depth = treeDepth(root, exp)
  const rows: HeaderCell[][] = Array.from({ length: depth }, () => [])

  function place(node: EventStandingsTreeNode, colStart: number, row: number): void {
    const span = leafColumnCount(node, exp)
    const isExp = node.has_children && exp.has(node.id)

    if (!isExp) {
      rows[row].push({
        key: `n-${node.id}-${row}`,
        label: node.name,
        colspan: 1,
        rowspan: depth - row,
        nodeId: node.id,
        expandable: node.has_children,
        expanded: false,
      })
      return
    }

    rows[row].push({
      key: `h-${node.id}-${row}`,
      label: node.name,
      colspan: span,
      rowspan: 1,
      nodeId: node.id,
      expandable: true,
      expanded: true,
    })

    rows[row + 1].push({
      key: `t-${node.id}-${row + 1}`,
      label: t('events.standingsTotal'),
      colspan: 1,
      rowspan: depth - (row + 1),
      nodeId: node.id,
      expandable: false,
      expanded: false,
      isTotal: true,
    })

    let col = colStart + 1
    for (const child of node.children) {
      place(child, col, row + 1)
      col += leafColumnCount(child, exp)
    }
  }

  place(root, 0, 0)
  return { rows, leaves }
}

const headerModel = computed(() => {
  const tree = data.value?.tree
  if (!tree) return { rows: [] as HeaderCell[][], leaves: [] as LeafCol[] }
  return buildHeaderRows(tree, expanded.value)
})

const headerRowCount = computed(() => Math.max(1, headerModel.value.rows.length))

function collectDescendantIds(node: EventStandingsTreeNode): number[] {
  return node.children.flatMap((c) => [c.id, ...collectDescendantIds(c)])
}

function findNode(node: EventStandingsTreeNode, id: number): EventStandingsTreeNode | null {
  if (node.id === id) return node
  for (const child of node.children) {
    const found = findNode(child, id)
    if (found) return found
  }
  return null
}

function toggleExpand(nodeId: number): void {
  const tree = data.value?.tree
  if (!tree) return
  const node = findNode(tree, nodeId)
  if (!node?.has_children) return

  const next = new Set(expanded.value)
  if (next.has(nodeId)) {
    next.delete(nodeId)
    for (const id of collectDescendantIds(node)) next.delete(id)
  } else {
    next.add(nodeId)
  }
  expanded.value = next
}

function scoreOf(row: EventStandingsTree['standings'][number], nodeId: number): number {
  return Number(row.scores?.[String(nodeId)] ?? 0)
}

function clubLocationLabel(row: { distrito?: string | null; iglesia?: string | null }): string {
  const distrito = (row.distrito || '').trim()
  const iglesia = (row.iglesia || '').trim()
  const hasDistrito = distrito !== '' && distrito !== '—'
  if (hasDistrito && iglesia) return `${distrito} - ${iglesia}`
  if (hasDistrito) return distrito
  return iglesia
}

function podiumClass(puesto: number): string {
  if (puesto === 1) return 'is-gold'
  if (puesto === 2) return 'is-silver'
  if (puesto === 3) return 'is-bronze'
  return ''
}

async function load(): Promise<void> {
  loading.value = true
  try {
    data.value = await eventsService.standingsTree(eventId.value, {
      sort: sort.value,
      q: search.value.trim() || undefined,
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
          :alt="data?.evento.name || t('events.standingsTreeTitle')"
        />
        <div class="standings-hero__copy">
          <Button
            type="button"
            text
            icon="pi pi-arrow-left"
            :label="t('common.back')"
            class="standings-back"
            @click="router.push({ name: 'events' })"
          />
          <p class="standings-kicker">{{ t('events.standingsTreeKicker') }}</p>
          <h1 class="pj-page__title standings-title">
            {{ data?.evento.name || t('events.standingsTreeTitle') }}
          </h1>
          <p class="pj-muted">{{ t('events.standingsTreeSubtitle') }}</p>
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
        input-id="standings-tree-search"
        icon="pi pi-users"
        :label="t('events.standingsSearchLabel')"
        :placeholder="t('events.standingsSearch')"
        :hint="t('segurosConsulta.liveSearchHint')"
      />
      <p class="expand-hint">{{ t('events.standingsTreeHint') }}</p>

      <section class="pj-panel standings-panel">
        <div class="tree-scroll">
          <table class="tree-table">
            <thead>
              <tr v-for="(row, ri) in headerModel.rows" :key="`hr-${ri}`">
                <th
                  v-if="ri === 0"
                  class="sticky sticky-place"
                  :rowspan="headerRowCount"
                >
                  {{ t('events.standingsPlace') }}
                </th>
                <th
                  v-if="ri === 0"
                  class="sticky sticky-club"
                  :rowspan="headerRowCount"
                >
                  {{ t('events.standingsClub') }}
                </th>
                <th
                  v-if="ri === 0"
                  class="sticky sticky-inscripcion"
                  :rowspan="headerRowCount"
                >
                  {{ t('events.standingsInscription') }}
                </th>
                <th
                  v-if="ri === 0"
                  class="sticky sticky-pct"
                  :rowspan="headerRowCount"
                >
                  {{ t('events.standingsPct') }}
                </th>
                <th
                  v-for="cell in row"
                  :key="cell.key"
                  :colspan="cell.colspan"
                  :rowspan="cell.rowspan"
                  class="score-head"
                  :class="{
                    'is-expandable': cell.expandable,
                    'is-expanded': cell.expanded,
                    'is-total': cell.isTotal,
                  }"
                  @click="cell.expandable ? toggleExpand(cell.nodeId) : undefined"
                >
                  <span class="score-head__inner">
                    <i
                      v-if="cell.expandable"
                      class="pi"
                      :class="cell.expanded ? 'pi-chevron-left' : 'pi-chevron-right'"
                    />
                    <span>{{ cell.label }}</span>
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!data.standings.length">
                <td :colspan="4 + headerModel.leaves.length" class="empty-cell">
                  {{ t('events.standingsEmpty') }}
                </td>
              </tr>
              <tr v-for="row in data.standings" :key="row.organizacion_id">
                <td class="sticky sticky-place">
                  <span class="place" :class="podiumClass(row.puesto)">{{ row.puesto }}</span>
                </td>
                <td class="sticky sticky-club">
                  <div class="club-cell">
                    <span v-if="row.logo_url" class="club-cell__logo">
                      <img :src="row.logo_url" :alt="row.nombre" />
                    </span>
                    <span v-else class="club-cell__fallback">
                      <i class="pi pi-flag" />
                    </span>
                    <div>
                      <strong>{{ row.nombre }}</strong>
                      <small v-if="clubLocationLabel(row)" class="pj-muted">
                        {{ clubLocationLabel(row) }}
                      </small>
                    </div>
                  </div>
                </td>
                <td class="sticky sticky-inscripcion score-cell">
                  {{ row.puntos_inscripcion ?? '—' }}
                </td>
                <td class="sticky sticky-pct score-cell">
                  {{ row.porcentaje != null ? `${row.porcentaje}%` : '—' }}
                </td>
                <td
                  v-for="col in headerModel.leaves"
                  :key="`${row.organizacion_id}-${col.key}`"
                  class="score-cell"
                  :class="{ 'is-total': col.isTotal || col.nodeId === data.tree.id }"
                >
                  <strong>{{ scoreOf(row, col.nodeId) }}</strong>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
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
  overflow: hidden;
  isolation: isolate;
  background:
    linear-gradient(135deg, color-mix(in srgb, #0f766e 18%, transparent), transparent 55%),
    linear-gradient(180deg, color-mix(in srgb, #f8fafc 88%, #ecfdf5), #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.standings-hero.has-cover {
  min-height: 11rem;
  color: #fff;
  background-image:
    linear-gradient(180deg, rgba(7, 18, 42, 0.28) 0%, rgba(7, 18, 42, 0.78) 100%),
    var(--hero-image);
  background-size: cover;
  background-position: center;
  border-color: transparent;
}

.standings-hero.has-cover .pj-muted,
.standings-hero.has-cover .standings-kicker {
  color: rgba(255, 255, 255, 0.86);
}

.standings-hero.has-cover .standings-summary > div {
  background: color-mix(in srgb, #07122a 45%, transparent);
  border-color: rgba(255, 255, 255, 0.18);
  color: #fff;
}

.standings-hero.has-cover .standings-summary span {
  color: rgba(255, 255, 255, 0.78);
}

.standings-hero.has-cover :deep(.standings-back) {
  color: #fff;
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
  overflow: visible;
  margin-bottom: 2.4rem;
}

.standings-hero.has-cover.has-logo .standings-hero__intro {
  align-items: flex-end;
}

.standings-hero.has-cover.has-logo .standings-hero__logo {
  position: relative;
  z-index: 2;
  margin-bottom: -2.4rem;
}

.standings-back {
  margin-left: -0.5rem;
}

.standings-kicker {
  margin: 0.35rem 0 0.15rem;
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

.standings-toolbar {
  display: grid;
  grid-template-columns: minmax(12rem, 16rem);
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

.expand-hint {
  margin: 0;
  font-size: 0.82rem;
  color: var(--pj-text-muted, #64748b);
  line-height: 1.35;
}

.standings-panel {
  padding: 0.35rem;
}

.tree-scroll {
  overflow: auto;
  max-width: 100%;
}

.tree-table {
  width: max-content;
  min-width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.92rem;
}

.tree-table th,
.tree-table td {
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-right: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
  padding: 0.65rem 0.75rem;
  vertical-align: middle;
  background: #fff;
}

.tree-table thead th {
  position: sticky;
  top: 0;
  z-index: 3;
  background: #f8fafc;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #334155;
  white-space: nowrap;
}

.sticky {
  position: sticky;
  z-index: 4;
  background: #fff;
}

.tree-table thead .sticky {
  z-index: 5;
  background: #f1f5f9;
}

.sticky-place {
  left: 0;
  min-width: 4.5rem;
  width: 4.5rem;
}

.sticky-club {
  left: 4.5rem;
  min-width: 15rem;
  max-width: 20rem;
}

.sticky-inscripcion {
  left: 19.5rem;
  min-width: 6.5rem;
  text-align: right;
}

.sticky-pct {
  left: 26rem;
  min-width: 4.5rem;
  text-align: right;
  box-shadow: 4px 0 8px -6px rgba(15, 23, 42, 0.25);
}

.score-head.is-expandable {
  cursor: pointer;
  user-select: none;
  background: color-mix(in srgb, #0f766e 10%, #f8fafc);
}

.score-head.is-expandable:hover {
  background: color-mix(in srgb, #0f766e 18%, #f8fafc);
}

.score-head.is-expanded {
  background: color-mix(in srgb, #0f766e 16%, #ecfdf5);
}

.score-head.is-total {
  background: color-mix(in srgb, #0f766e 8%, #fff);
  font-weight: 700;
}

.score-head__inner {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.score-cell {
  text-align: right;
  font-variant-numeric: tabular-nums;
  min-width: 5.5rem;
}

.score-cell.is-total strong {
  color: #0f766e;
}

.empty-cell {
  text-align: center;
  color: var(--pj-text-muted, #64748b);
  padding: 1.5rem !important;
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

@media (max-width: 900px) {
  .standings-toolbar {
    grid-template-columns: 1fr;
  }

  .sticky-inscripcion,
  .sticky-pct {
    left: 4.5rem;
    box-shadow: none;
  }

  .sticky-pct {
    left: 11rem;
  }

  .tree-table thead .sticky-club,
  .tree-table tbody .sticky-club {
    display: none;
  }
}
</style>
