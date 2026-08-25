<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import PageLoader from '@/components/PageLoader.vue'
import EventSearchPanel from '@/components/events/EventSearchPanel.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import EventJudgeTreeNodes from '@/components/events/EventJudgeTreeNodes.vue'
import EventJudgeActivityCard from '@/components/events/EventJudgeActivityCard.vue'
import { eventsService } from '@/services/eventsService'
import { resolveAssetUrl, toCssImageUrl } from '@/modules/settings/assetUrl'
import { extractBannerHeroVars } from '@/utils/dominantColor'
import { getApiErrorMessage } from '@/services/api'
import type {
  EventoEvidenciaItem,
  JudgeBoard,
  JudgeClub,
  JudgeClubResumen,
  JudgeDetailTab,
  JudgeNodeStatus,
  JudgeSubevento,
  JudgeTreeNode,
  ParticipationCalificacion,
} from '@/modules/events/types'
import { previewFromEvidenceUrl } from '@/modules/events/evidencePreview'

type ClubFilter = 'todos' | 'pendientes' | 'evaluados'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const board = ref<JudgeBoard | null>(null)
const selectedSubeventoId = ref<number | null>(null)
const selectedActividadId = ref<number | null>(null)
const selectedOrgId = ref<number | null>(null)
const clubFilter = ref<ClubFilter>('pendientes')
const search = ref('')
const observaciones = ref('')
const genericScore = ref<number | null>(null)
const puestoEntrega = ref('')
const durMin = ref(0)
const durSec = ref(0)
const durCs = ref(0)
const resultadoObtenido = ref<number | null>(null)
const criterioScores = ref<Record<number, number>>({})
const selectedEvidenceId = ref<number | null>(null)
const expandedTreeIds = ref<Set<number>>(new Set())
const treeInitialized = ref(false)
const drawerVisible = ref(false)
const treeSheetVisible = ref(false)
const isMobile = useMediaQuery('(max-width: 900px)')
const drawerPosition = computed(() => (isMobile.value ? 'bottom' : 'right'))

const eventId = computed(() => Number(route.params.id))
const bannerUrl = computed(() => resolveAssetUrl(board.value?.evento.banner_url))
const logoUrl = computed(() => resolveAssetUrl(board.value?.evento.image_url))
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

const subevento = computed<JudgeSubevento | null>(() => board.value?.subevento ?? null)
const actividad = computed<JudgeSubevento | null>(() => board.value?.actividad ?? board.value?.subevento ?? null)

const selectedClub = computed<JudgeClub | JudgeClubResumen | null>(() => {
  if (!board.value || !selectedOrgId.value) return null
  const fromActivity = board.value.clubes.find((c) => c.organizacion_id === selectedOrgId.value)
  if (fromActivity) return fromActivity
  return board.value.clubes_resumen?.find((c) => c.organizacion_id === selectedOrgId.value) ?? null
})

const clubesCatalog = computed<JudgeClubResumen[]>(() => board.value?.clubes_resumen ?? [])

const filteredClubs = computed(() => {
  const q = search.value.trim().toLowerCase()
  return clubesCatalog.value.filter((club) => {
    if (clubFilter.value === 'pendientes' && (club.eventos_pendientes ?? 0) <= 0) return false
    if (clubFilter.value === 'evaluados' && club.estado !== 'evaluado') return false
    if (q && !club.nombre.toLowerCase().includes(q)) return false
    return true
  })
})

/** Clubes del alcance actual (sin filtro de búsqueda) para navegar en el drawer. */
const drawerClubs = computed(() => {
  if (board.value?.clubes?.length) return board.value.clubes
  return clubesCatalog.value
})

const clubNavIndex = computed(() =>
  drawerClubs.value.findIndex((c) => c.organizacion_id === selectedOrgId.value),
)

const clubNavLabel = computed(() => {
  const idx = clubNavIndex.value
  if (idx < 0 || !drawerClubs.value.length) return ''
  return `${idx + 1} / ${drawerClubs.value.length}`
})

const canGoPrevClub = computed(() => clubNavIndex.value > 0)
const canGoNextClub = computed(() => {
  const idx = clubNavIndex.value
  return idx >= 0 && idx < drawerClubs.value.length - 1
})

const filterCounts = computed(() => {
  const clubs = clubesCatalog.value
  return {
    todos: clubs.length,
    pendientes: clubs.filter((c) => (c.eventos_pendientes ?? 0) > 0).length,
    evaluados: clubs.filter((c) => c.estado === 'evaluado').length,
  }
})

const clubHasSelection = computed(() => selectedOrgId.value != null)

const judgeTree = computed(() => board.value?.arbol ?? [])

const clubPendientes = computed(() => {
  if (!selectedOrgId.value || !board.value?.pendientes) return {} as Record<string, number>
  return board.value.pendientes[String(selectedOrgId.value)] || {}
})

const selectedClubPendingTotal = computed(() => {
  if (!selectedOrgId.value) return 0
  const row = clubesCatalog.value.find((c) => c.organizacion_id === selectedOrgId.value)
  if (row) return row.eventos_pendientes ?? 0
  return Object.keys(clubPendientes.value).length
})

const clubEvaluados = computed(() => {
  if (!selectedOrgId.value || !board.value?.evaluados) return {} as Record<string, number>
  return board.value.evaluados[String(selectedOrgId.value)] || {}
})

const clubEvidencias = computed(() => {
  if (!selectedOrgId.value || !board.value?.evidencias) return {} as Record<number, number>
  const raw = board.value.evidencias[String(selectedOrgId.value)] || {}
  const map: Record<number, number> = {}
  for (const [id, count] of Object.entries(raw)) {
    map[Number(id)] = Number(count) || 0
  }
  return map
})

const selectedActivityClub = computed(() => activityClubForOrg(selectedOrgId.value))

const treeSelectedId = computed(() => selectedActividadId.value ?? selectedSubeventoId.value)

const canScoreActivity = computed(() => {
  const act = actividad.value
  if (!act) return false
  if (act.puede_calificar != null) return Boolean(act.puede_calificar)
  return Boolean(act.es_calificable)
})

function nodeCanScore(node: JudgeTreeNode): boolean {
  if (node.puede_calificar != null) return Boolean(node.puede_calificar)
  return Boolean(node.es_calificable)
}

const activityDefaultTab = computed<JudgeDetailTab>(() => {
  if (selectedClub.value && canScoreActivity.value) return 'calificacion'
  return 'info'
})

const obsResultado = computed<ParticipationCalificacion | null>(() => {
  const club = selectedActivityClub.value
  if (!club) return null
  const cal = club.calificacion
  if (!cal && !club.observaciones_director) return null
  return {
    id: cal?.id ?? null,
    puntaje_obtenido: cal?.puntaje_obtenido ?? 0,
    observaciones: cal?.observaciones ?? null,
    calificado_por: cal?.calificado_por ?? null,
    updated_at: cal?.updated_at ?? null,
    detalles: cal?.detalles ?? [],
    observaciones_director:
      club.observaciones_director ?? cal?.observaciones_director ?? null,
    observaciones_director_updated_at:
      club.observaciones_director_updated_at ??
      cal?.observaciones_director_updated_at ??
      null,
  }
})

const canWriteJudgeObs = computed(
  () => canScoreActivity.value && Boolean(selectedActivityClub.value?.calificacion),
)

const drawerTitle = computed(() => {
  if (selectedClub.value) return selectedClub.value.nombre
  return actividad.value?.name || t('events.judgeTitle')
})

const drawerSubtitle = computed(() => {
  if (selectedClub.value && actividad.value) return actividad.value.name
  if (subevento.value && actividad.value && subevento.value.id !== actividad.value.id) {
    return `${subevento.value.name} › ${actividad.value.name}`
  }
  return board.value?.evento.name || ''
})

function findTreeNode(nodes: JudgeTreeNode[], id: number): JudgeTreeNode | null {
  for (const node of nodes) {
    if (node.id === id) return node
    const found = findTreeNode(node.hijos ?? [], id)
    if (found) return found
  }
  return null
}

function findTreeParent(
  nodes: JudgeTreeNode[],
  id: number,
  parent: JudgeTreeNode | null = null,
): JudgeTreeNode | null | undefined {
  for (const node of nodes) {
    if (node.id === id) return parent
    const found = findTreeParent(node.hijos ?? [], id, node)
    if (found !== undefined) return found
  }
  return undefined
}

const parentEventName = computed(() => {
  const actId = actividad.value?.id
  if (!actId) return board.value?.evento.name || ''

  const treeParent = findTreeParent(judgeTree.value, actId)
  if (treeParent?.name) return treeParent.name

  if (subevento.value && subevento.value.id !== actId) return subevento.value.name

  const rootName = board.value?.evento.name
  if (rootName && rootName !== actividad.value?.name) return rootName
  return ''
})

/** Hijos del evento padre actual (rama seleccionada o actividad con hijos). */
const activityChildNodes = computed(() => {
  const tree = judgeTree.value
  const branchId = subevento.value?.id
  if (branchId) {
    const branch = findTreeNode(tree, branchId)
    if (branch?.hijos?.length) return branch.hijos
  }
  const actId = actividad.value?.id
  if (actId) {
    const act = findTreeNode(tree, actId)
    if (act?.hijos?.length) return act.hijos
  }
  return [] as JudgeTreeNode[]
})

const treePendingById = computed(() => {
  const map: Record<number, number> = {}
  const walk = (nodes: JudgeTreeNode[]): number => {
    let sum = 0
    for (const node of nodes) {
      const self = nodeCanScore(node) ? pendingForEvento(node.id) : 0
      const children = node.hijos?.length ? walk(node.hijos) : 0
      const total = self + children
      map[node.id] = total
      sum += total
    }
    return sum
  }
  walk(judgeTree.value)
  return map
})

const treeStatusById = computed(() => {
  const map: Record<number, JudgeNodeStatus> = {}
  const walk = (nodes: JudgeTreeNode[]): JudgeNodeStatus => {
    let anyPending = false
    let anyScored = false
    let anyRelevant = false
    for (const node of nodes) {
      let status: JudgeNodeStatus = 'neutral'
      const childStatus = node.hijos?.length ? walk(node.hijos) : 'neutral'
      const pending = pendingForEvento(node.id)
      const scored = isEvaluado(node.id)

      if (nodeCanScore(node)) {
        anyRelevant = true
        if (pending > 0) {
          status = 'pendiente'
          anyPending = true
        } else if (scored) {
          status = 'evaluado'
          anyScored = true
        }
      }

      if (childStatus === 'pendiente') {
        status = 'pendiente'
        anyPending = true
        anyRelevant = true
      } else if (status === 'neutral' && childStatus === 'evaluado') {
        status = 'evaluado'
        anyScored = true
        anyRelevant = true
      } else if (childStatus === 'evaluado') {
        anyScored = true
        anyRelevant = true
        if (status === 'neutral') status = 'evaluado'
      }

      map[node.id] = status
    }
    if (anyPending) return 'pendiente'
    if (anyScored) return 'evaluado'
    return anyRelevant ? 'neutral' : 'neutral'
  }
  walk(judgeTree.value)
  return map
})

function pendingForEvento(eventoId: number): number {
  return Number(clubPendientes.value[String(eventoId)] || 0)
}

function isEvaluado(eventoId: number): boolean {
  return clubEvaluados.value[String(eventoId)] != null
}

function collectExpandableIds(nodes: JudgeTreeNode[], into: Set<number>): void {
  for (const node of nodes) {
    if (node.hijos?.length) {
      into.add(node.id)
      collectExpandableIds(node.hijos, into)
    }
  }
}

function syncExpandedTree(nodes: JudgeTreeNode[]): void {
  const next = new Set<number>()
  collectExpandableIds(nodes, next)
  expandedTreeIds.value = next
}

function toggleTreeNode(id: number): void {
  const next = new Set(expandedTreeIds.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expandedTreeIds.value = next
}

function findSelectableFor(eventoId: number) {
  const all = board.value?.subeventos ?? []
  const candidates = all.filter(
    (s) => s.id === eventoId || (s.actividad_ids?.includes(eventoId) ?? false),
  )
  if (!candidates.length) return null
  return [...candidates].sort((a, b) => (b.depth ?? 0) - (a.depth ?? 0))[0]
}

async function onTreeNodeSelect(node: JudgeTreeNode): Promise<void> {
  if (!clubHasSelection.value) {
    toast.add({
      severity: 'info',
      summary: t('events.judgePhaseClubFirst'),
      detail: t('events.judgeSelectClubFirst'),
      life: 3000,
    })
    return
  }

  const sel = findSelectableFor(node.id)
  if (!sel) return

  const isLeafActivity = Boolean(nodeCanScore(node) && node.id !== sel.id)
  const isSelfCalificable =
    Boolean(nodeCanScore(node) && node.id === sel.id && !sel.tiene_hijos_calificables)

  if (isLeafActivity || isSelfCalificable) {
    if (selectedSubeventoId.value === sel.id && selectedActividadId.value === node.id) {
      openGradingDrawer()
      return
    }
    selectedSubeventoId.value = sel.id
    selectedActividadId.value = node.id
    await load(true)
    openGradingDrawer()
    return
  }

  await onSubeventoChange(sel.id)
  openGradingDrawer()
}

function openGradingDrawer(): void {
  if (isMobile.value) treeSheetVisible.value = false
  drawerVisible.value = true
}

const hasCriteria = computed(() => (actividad.value?.criterios?.length ?? 0) > 0)

const resultadoEsperado = computed(() => actividad.value?.resultado_esperado ?? null)
const scoreByParticipation = computed(() => Boolean(actividad.value?.puntaje_por_participar))

function formatDuration(min: number, sec: number, cs: number): string {
  const m = Math.max(0, Math.floor(Number(min) || 0))
  const s = Math.min(59, Math.max(0, Math.floor(Number(sec) || 0)))
  const c = Math.min(99, Math.max(0, Math.floor(Number(cs) || 0)))
  return `${m}:${String(s).padStart(2, '0')}.${String(c).padStart(2, '0')}`
}

function parseDuration(value: string | null | undefined): { min: number; sec: number; cs: number } {
  const match = /^(\d{1,3}):([0-5]\d)\.(\d{2})$/.exec((value || '').trim())
  if (!match) return { min: 0, sec: 0, cs: 0 }
  return { min: Number(match[1]), sec: Number(match[2]), cs: Number(match[3]) }
}

const tiempoEntrega = computed(() => formatDuration(durMin.value, durSec.value, durCs.value))

const scoredTotal = computed(() => {
  if (!actividad.value) return 0
  if (scoreByParticipation.value) {
    return Number(actividad.value.puntaje_maximo ?? 0)
  }
  if (hasCriteria.value) {
    return actividad.value.criterios.reduce((sum, c) => sum + (Number(criterioScores.value[c.id]) || 0), 0)
  }
  const expected = resultadoEsperado.value
  if (expected != null && expected > 0 && resultadoObtenido.value != null) {
    const max = Number(actividad.value.puntaje_maximo ?? 0)
    return Math.round((resultadoObtenido.value / expected) * max * 100) / 100
  }
  return Number(genericScore.value) || 0
})

const maxScore = computed(() => actividad.value?.puntaje_maximo ?? null)

const scorePct = computed(() => {
  if (!maxScore.value) return 0
  return Math.min(100, Math.round((scoredTotal.value / maxScore.value) * 100))
})

const scoreOverflow = computed(() => {
  if (maxScore.value == null) return false
  return scoredTotal.value > maxScore.value + 0.001
})

const selectedEvidence = computed<EventoEvidenciaItem | null>(() => {
  const list = selectedActivityClub.value?.evidencias ?? []
  if (!list.length) return null
  if (selectedEvidenceId.value) {
    return list.find((e) => e.id === selectedEvidenceId.value) ?? list[0]
  }
  return list[0]
})

const evidencePreview = computed(() => {
  const ev = selectedEvidence.value
  if (!ev?.url) return null
  return previewFromEvidenceUrl(ev.url, {
    preferredTipo: ev.tipo,
    title: ev.titulo,
  })
})

function hydrateForm(club: JudgeClub | null): void {
  observaciones.value = club?.calificacion?.observaciones || ''
  const details = club?.calificacion?.detalles ?? []
  const map: Record<number, number> = {}
  for (const c of actividad.value?.criterios ?? []) {
    const found = details.find((d) => d.criterio_evaluacion_id === c.id)
    map[c.id] = found ? Number(found.puntos) : 0
  }
  criterioScores.value = map
  genericScore.value = club?.calificacion ? Number(club.calificacion.puntaje_obtenido) : null
  puestoEntrega.value = club?.calificacion?.puesto_entrega || ''
  const duration = parseDuration(club?.calificacion?.tiempo_entrega)
  durMin.value = duration.min
  durSec.value = duration.sec
  durCs.value = duration.cs
  resultadoObtenido.value =
    club?.calificacion?.resultado_obtenido != null ? Number(club.calificacion.resultado_obtenido) : null
  selectedEvidenceId.value = club?.evidencias?.[0]?.id ?? null
}

function activityClubForOrg(orgId: number | null): JudgeClub | null {
  if (!orgId || !board.value) return null
  return board.value.clubes.find((c) => c.organizacion_id === orgId) ?? null
}

async function load(keepClub = false): Promise<void> {
  const prevOrg = keepClub ? selectedOrgId.value : null
  loading.value = true
  try {
    if (!keepClub && !selectedSubeventoId.value) {
      const qSub = route.query.subevento_id
      if (qSub != null && qSub !== '') {
        const sid = Number(qSub)
        if (Number.isFinite(sid) && sid > 0) {
          selectedSubeventoId.value = sid
          selectedActividadId.value = sid
        }
      }
    }
    board.value = await eventsService.judgeBoard(
      eventId.value,
      selectedSubeventoId.value,
      selectedActividadId.value,
    )
    if (board.value.arbol?.length && !treeInitialized.value) {
      syncExpandedTree(board.value.arbol)
      treeInitialized.value = true
    }
    if (!selectedSubeventoId.value && board.value.subevento) {
      selectedSubeventoId.value = board.value.subevento.id
    }
    if (board.value.actividad) {
      selectedActividadId.value = board.value.actividad.id
    }
    const clubs = board.value.clubes
    if (prevOrg) {
      selectedOrgId.value = prevOrg
    } else {
      const qOrg = route.query.organizacion_id
      if (qOrg != null && qOrg !== '') {
        const orgId = Number(qOrg)
        const fromResumen = (board.value.clubes_resumen ?? []).find(
          (c) => c.organizacion_id === orgId,
        )
        const fromClubes = clubs.find((c) => c.organizacion_id === orgId)
        if (fromResumen || fromClubes) {
          selectedOrgId.value = orgId
          if (isMobile.value) treeSheetVisible.value = true
          else drawerVisible.value = true
        }
      }
    }
    // Fase 1: no auto-seleccionar club; el juez elige primero.
    hydrateForm(activityClubForOrg(selectedOrgId.value))
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
    router.push({ name: 'events' })
  } finally {
    loading.value = false
  }
}

async function onSubeventoChange(id: number | null): Promise<void> {
  if (id === selectedSubeventoId.value && board.value?.subevento?.id === id && !selectedActividadId.value) {
    return
  }
  selectedSubeventoId.value = id
  selectedActividadId.value = null
  await load(true)
}

function selectClub(club: JudgeClub | JudgeClubResumen): void {
  const keepDrawer = drawerVisible.value
  selectedOrgId.value = club.organizacion_id
  hydrateForm(activityClubForOrg(club.organizacion_id))
  if (isMobile.value) {
    if (keepDrawer && actividad.value) {
      drawerVisible.value = true
      return
    }
    treeSheetVisible.value = true
    return
  }
  if (keepDrawer && actividad.value) {
    drawerVisible.value = true
  }
}

function clubPendingCount(club: JudgeClubResumen): number {
  return club.eventos_pendientes ?? 0
}

function criterionMax(criterioId: number): number {
  const c = actividad.value?.criterios.find((x) => x.id === criterioId)
  return c ? Number(c.puntos) : 0
}

function clampCriterion(criterioId: number, value: number | null): void {
  const max = criterionMax(criterioId)
  let next = Number(value) || 0
  if (next < 0) next = 0
  if (next > max) next = max
  criterioScores.value = { ...criterioScores.value, [criterioId]: next }
}

function clubStatusMeta(estado: string): { label: string; css: string } {
  if (estado === 'evaluado') {
    return { label: t('events.statusScored'), css: 'is-scored' }
  }
  return { label: t('events.statusPending'), css: 'is-pending' }
}

function evidenceLabel(ev: EventoEvidenciaItem): string {
  return ev.titulo || ev.tipo || t('events.evidenceTitle')
}

function scorePayload() {
  const activity = actividad.value
  const orgId = selectedOrgId.value
  if (!activity || !orgId) {
    throw new Error('Actividad u organización no seleccionada')
  }
  return {
    organizacion_id: orgId,
    observaciones: observaciones.value || null,
    puntaje_obtenido: scoredTotal.value,
    criterios:
      hasCriteria.value && !scoreByParticipation.value
        ? activity.criterios.map((c) => ({
            criterio_evaluacion_id: c.id,
            puntos: Number(criterioScores.value[c.id]) || 0,
          }))
        : undefined,
    puesto_entrega: activity.requiere_puesto_entrega ? puestoEntrega.value.trim() || null : null,
    tiempo_entrega: activity.requiere_tiempo_entrega ? tiempoEntrega.value : null,
    resultado_obtenido:
      resultadoEsperado.value != null ? resultadoObtenido.value : null,
  }
}

async function saveAndMaybeNext(goNext: boolean): Promise<void> {
  if (!actividad.value || !selectedOrgId.value) return
  if (!canScoreActivity.value) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: actividad.value.es_calificable
        ? t('events.judgeNotAssigned')
        : t('events.judgeReadOnlyParent'),
      life: 4000,
    })
    return
  }
  if (!actividad.value.es_calificable) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.judgePickChildActivity'),
      life: 3500,
    })
    return
  }
  if (scoreOverflow.value) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.judgeScoreOverflow'),
      life: 3500,
    })
    return
  }

  if (hasCriteria.value) {
    for (const c of actividad.value.criterios) {
      const pts = Number(criterioScores.value[c.id]) || 0
      if (pts > Number(c.puntos) + 0.001) {
        toast.add({
          severity: 'warn',
          summary: t('common.error'),
          detail: t('events.judgeCriterionOverflow', { name: c.nombre, max: c.puntos }),
          life: 3500,
        })
        return
      }
    }
  }

  saving.value = true
  try {
    await eventsService.saveCalificacion(actividad.value.id, scorePayload())
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.judgeSaved'),
      life: 2500,
    })
    await load(true)
    if (goNext) {
      const idx = drawerClubs.value.findIndex((c) => c.organizacion_id === selectedOrgId.value)
      const next =
        drawerClubs.value[idx + 1] || drawerClubs.value.find((c) => c.estado === 'pendiente')
      if (next) selectClub(next)
    }
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

async function saveJudgeObservacion(): Promise<void> {
  if (!canWriteJudgeObs.value) {
    toast.add({
      severity: 'warn',
      summary: t('common.error'),
      detail: t('events.obsJudgeNeedScore'),
      life: 3500,
    })
    return
  }
  if (!actividad.value || !selectedOrgId.value) return
  saving.value = true
  try {
    await eventsService.saveCalificacion(actividad.value.id, scorePayload())
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.directorObsSaved'),
      life: 2500,
    })
    await load(true)
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}

function goPrevClub(): void {
  if (!canGoPrevClub.value) return
  selectClub(drawerClubs.value[clubNavIndex.value - 1])
}

function goNextClub(): void {
  if (!canGoNextClub.value) return
  selectClub(drawerClubs.value[clubNavIndex.value + 1])
}

function onDrawerClubChange(orgId: number | null): void {
  if (orgId == null) return
  const club = drawerClubs.value.find((c) => c.organizacion_id === orgId)
  if (club) selectClub(club)
}

watch(
  () => selectedOrgId.value,
  () => {
    hydrateForm(activityClubForOrg(selectedOrgId.value))
  },
)

watch(isMobile, (mobile) => {
  if (!mobile) treeSheetVisible.value = false
})

watch(drawerVisible, (open) => {
  if (!open && isMobile.value && clubHasSelection.value) {
    treeSheetVisible.value = true
  }
})

onMounted(() => {
  load(false)
})
</script>

<template>
  <div class="judge-page">
    <PageLoader v-if="loading && !board" />

    <template v-else-if="board">
      <div
        class="judge-top"
        :class="{
          'has-cover': Boolean(heroCoverUrl),
          'has-logo': showEventLogo,
        }"
        :style="heroStyle"
      >
        <div class="judge-top__intro">
          <img
            v-if="showEventLogo && logoUrl"
            class="judge-top__logo"
            :src="logoUrl"
            :alt="board.evento.name"
          />
          <h1>{{ board.evento.name }}</h1>
        </div>

        <div class="judge-top__meta">
          <div class="judge-top__stats">
            <div class="progress-pill">
              <strong>{{ board.progreso.evaluados }}/{{ board.progreso.total }}</strong>
              <span>{{ t('events.judgeProgress', { pct: board.progreso.pct }) }}</span>
            </div>
            <div v-if="actividad" class="max-chip">
              <span>{{ t('events.maxScoreLabel') }}</span>
              <strong>{{ actividad.puntaje_maximo ?? '—' }} pts</strong>
            </div>
          </div>
          <Button
            type="button"
            outlined
            icon="pi pi-list"
            :label="t('events.judgeMyEvaluations')"
            @click="
              router.push({
                name: 'events.judge.evaluaciones',
                params: { id: eventId },
              })
            "
          />
        </div>
      </div>

      <p v-if="!judgeTree.length" class="pj-muted empty">{{ t('events.judgeNoSubevents') }}</p>

      <div v-else class="judge-layout">
        <aside class="panel panel--list">
          <div class="list-head">
            <div class="phase-chip">
              <span class="phase-chip__step">1</span>
              <div>
                <strong>{{ t('events.judgePhaseClub') }}</strong>
                <p class="pj-muted">{{ t('events.judgePhaseClubHint') }}</p>
              </div>
            </div>
            <EventSearchPanel
              v-model="search"
              input-id="judge-club-search"
              icon="pi pi-users"
              :label="t('events.standingsSearchLabel')"
              :placeholder="t('events.judgeSearchClub')"
              :hint="t('segurosConsulta.liveSearchHint')"
            />
            <div class="filter-tabs">
              <button
                type="button"
                :class="{ active: clubFilter === 'todos' }"
                @click="clubFilter = 'todos'"
              >
                {{ t('events.judgeFilterAll') }} ({{ filterCounts.todos }})
              </button>
              <button
                type="button"
                :class="{ active: clubFilter === 'pendientes' }"
                @click="clubFilter = 'pendientes'"
              >
                {{ t('events.judgeFilterPending') }} ({{ filterCounts.pendientes }})
              </button>
              <button
                type="button"
                :class="{ active: clubFilter === 'evaluados' }"
                @click="clubFilter = 'evaluados'"
              >
                {{ t('events.judgeFilterScored') }} ({{ filterCounts.evaluados }})
              </button>
            </div>
          </div>

          <button
            v-for="club in filteredClubs"
            :key="club.organizacion_id"
            type="button"
            class="club-item"
            :class="{ active: selectedOrgId === club.organizacion_id }"
            @click="selectClub(club)"
          >
            <div class="club-item__avatar">
              <img v-if="club.logo_url" :src="club.logo_url" :alt="club.nombre" />
              <i v-else class="pi pi-building" />
            </div>
            <div class="club-item__body">
              <strong>{{ club.nombre }}</strong>
              <span class="pj-muted">
                {{ t('events.judgeEvidencesCount', { count: club.evidencias_count }) }}
              </span>
              <span class="status-badge" :class="clubStatusMeta(club.estado).css">
                {{ clubStatusMeta(club.estado).label }}
              </span>
            </div>
            <div class="club-item__pending">
              <span
                v-if="clubPendingCount(club) > 0"
                class="pending-badge"
                :title="t('events.judgeClubPendingEvents', { count: clubPendingCount(club) })"
              >
                {{ clubPendingCount(club) > 99 ? '99+' : clubPendingCount(club) }}
              </span>
              <small v-else class="pj-muted">{{ t('events.judgeClubNoPending') }}</small>
            </div>
          </button>

          <p v-if="!filteredClubs.length" class="pj-muted empty">{{ t('events.judgeClubsEmpty') }}</p>
        </aside>

        <button
          v-if="isMobile && clubHasSelection && !treeSheetVisible && !drawerVisible"
          type="button"
          class="tree-reopen"
          @click="treeSheetVisible = true"
        >
          <i class="pi pi-sitemap" />
          <span>{{ t('events.judgeReopenEvents', { club: selectedClub?.nombre || '' }) }}</span>
        </button>

        <div
          v-if="isMobile && treeSheetVisible"
          class="tree-sheet-backdrop"
          @click="treeSheetVisible = false"
        />

        <aside
          class="panel panel--tree"
          :class="{
            'is-locked': !clubHasSelection,
            'is-sheet': isMobile,
            'is-sheet-open': isMobile && treeSheetVisible,
          }"
        >
          <div v-if="isMobile" class="tree-sheet__handle" aria-hidden="true" />
          <div class="tree-head">
            <div class="phase-chip">
              <span class="phase-chip__step" :class="{ 'is-ready': clubHasSelection }">2</span>
              <div>
                <strong>{{ t('events.judgePhaseEvents') }}</strong>
                <p class="pj-muted">
                  <template v-if="clubHasSelection">
                    {{ t('events.judgePhaseEventsHintSelected', { club: selectedClub?.nombre || '' }) }}
                    <template v-if="selectedClubPendingTotal > 0">
                      · {{ t('events.judgeClubPendingEvents', { count: selectedClubPendingTotal }) }}
                    </template>
                  </template>
                  <template v-else>
                    {{ t('events.judgePhaseEventsHintLocked') }}
                  </template>
                </p>
              </div>
            </div>
            <button
              v-if="isMobile"
              type="button"
              class="tree-sheet__close"
              :aria-label="t('events.judgeEventsSheetClose')"
              @click="treeSheetVisible = false"
            >
              <i class="pi pi-times" />
            </button>
          </div>

          <div v-if="!clubHasSelection" class="tree-lock">
            <i class="pi pi-lock" />
            <p>{{ t('events.judgeSelectClubFirst') }}</p>
          </div>

          <div v-else class="tree-body">
            <EventJudgeTreeNodes
              :nodes="judgeTree"
              :expanded="expandedTreeIds"
              :selected-id="treeSelectedId"
              :pending-by-id="treePendingById"
              :status-by-id="treeStatusById"
              :pending-label="t('events.statusPending')"
              :scored-label="t('events.statusScored')"
              @toggle="toggleTreeNode"
              @select="onTreeNodeSelect"
            />
          </div>
        </aside>
      </div>

      <AppStackDrawer
        v-model:visible="drawerVisible"
        :title="drawerTitle"
        :subtitle="drawerSubtitle"
        :level="1"
        :position="drawerPosition"
      >
        <template #header>
          <div class="drawer-club-head">
            <div class="club-item__avatar lg">
              <img
                v-if="selectedClub?.logo_url"
                :src="selectedClub.logo_url"
                :alt="selectedClub.nombre"
              />
              <i v-else class="pi pi-building" />
            </div>
            <div class="drawer-club-head__text">
              <strong>{{ selectedClub?.nombre || t('events.judgeSelectClub') }}</strong>
              <small v-if="actividad" class="drawer-event-line">{{ actividad.name }}</small>
            </div>
          </div>
        </template>

        <div v-if="loading" class="drawer-loading">
          <i class="pi pi-spin pi-spinner" />
          <span>{{ t('common.loading') }}</span>
        </div>

        <template v-else-if="actividad">
          <section class="club-switcher">
            <div v-if="selectedClub" class="club-switcher__tags">
              <span class="status-badge" :class="clubStatusMeta(selectedClub.estado).css">
                {{ clubStatusMeta(selectedClub.estado).label }}
              </span>
              <span class="club-switcher__score">
                {{
                  selectedActivityClub?.puntaje_obtenido != null
                    ? selectedActivityClub.puntaje_obtenido
                    : '—'
                }}
                /
                {{
                  selectedActivityClub?.puntaje_maximo ??
                  actividad.puntaje_maximo ??
                  '—'
                }}
                pts
              </span>
            </div>

            <div class="club-switcher__nav">
              <span v-if="parentEventName || clubNavLabel" class="club-switcher__count">
                <span v-if="parentEventName" class="club-switcher__parent">{{ parentEventName }}</span>
                <span v-if="clubNavLabel" class="club-switcher__index">{{ clubNavLabel }}</span>
              </span>
              <Select
                :model-value="selectedOrgId"
                :options="drawerClubs"
                option-label="nombre"
                option-value="organizacion_id"
                class="club-switcher__select"
                :placeholder="t('events.judgeClubPicker')"
                :disabled="!drawerClubs.length"
                @update:model-value="onDrawerClubChange"
              />
              <div class="club-switcher__arrows">
                <Button
                  type="button"
                  outlined
                  icon="pi pi-chevron-left"
                  :label="t('events.judgePrevClub')"
                  :disabled="!canGoPrevClub"
                  @click="goPrevClub"
                />
                <Button
                  type="button"
                  outlined
                  icon-pos="right"
                  icon="pi pi-chevron-right"
                  :label="t('events.judgeNextClub')"
                  :disabled="!canGoNextClub"
                  @click="goNextClub"
                />
              </div>
            </div>
          </section>

          <EventJudgeActivityCard
            :actividad="actividad"
            :default-tab="activityDefaultTab"
            :show-calificacion="canScoreActivity"
            :show-observaciones="true"
            observaciones-mode="judge"
            :resultado="obsResultado"
            :judge-observacion="observaciones"
            :can-write-judge-obs="canWriteJudgeObs"
            :saving-judge-obs="saving"
            :tip-text="canScoreActivity ? '' : t('events.judgeReadOnlyParent')"
            :subeventos="activityChildNodes"
            :evidencia-by-id="clubEvidencias"
            :has-club-selected="!!selectedOrgId"
            :show-participantes="
              actividad.participantes_min != null ||
              actividad.participantes_max != null ||
              Boolean(actividad.permite_inscribir_no_participantes) ||
              Boolean(selectedActivityClub?.participantes?.length)
            "
            @select-subevento="onTreeNodeSelect"
            @update:judge-observacion="observaciones = $event"
            @save-judge-obs="saveJudgeObservacion"
          >
            <template #participantes>
              <p v-if="!selectedActivityClub?.participantes?.length" class="pj-muted">
                {{ t('events.activityRosterJudgeEmpty') }}
              </p>
              <ul v-else class="judge-roster">
                <li v-for="row in selectedActivityClub.participantes" :key="row.id">
                  <strong>{{ row.nombre }}</strong>
                  <small v-if="row.sexo === 'M'">{{ t('events.activityRosterMale') }}</small>
                  <small v-else-if="row.sexo === 'F'">{{ t('events.activityRosterFemale') }}</small>
                </li>
              </ul>
            </template>
            <template #calificacion>
              <template v-if="selectedActivityClub">
                <div class="workspace">
                  <section class="score-panel">
                    <h3>{{ t('events.criteriaTitle') }}</h3>

                    <p v-if="scoreByParticipation" class="pj-muted">
                      {{ t('events.judgeParticipationScoreHint') }}
                    </p>

                    <div
                      v-if="
                        actividad.requiere_puesto_entrega ||
                        actividad.requiere_tiempo_entrega ||
                        resultadoEsperado != null
                      "
                      class="grading-extras"
                    >
                      <div v-if="actividad.requiere_puesto_entrega" class="field">
                        <label>{{ t('events.judgePuestoEntrega') }}</label>
                        <InputText v-model="puestoEntrega" class="w-full" />
                      </div>
                      <div v-if="actividad.requiere_tiempo_entrega" class="field">
                        <label>{{ t('events.judgeTiempoEntrega') }}</label>
                        <div class="duration-input">
                          <div>
                            <InputNumber v-model="durMin" :min="0" :max="999" input-class="w-full" />
                            <small>{{ t('events.judgeTiempoMin') }}</small>
                          </div>
                          <span>:</span>
                          <div>
                            <InputNumber v-model="durSec" :min="0" :max="59" input-class="w-full" />
                            <small>{{ t('events.judgeTiempoSec') }}</small>
                          </div>
                          <span>.</span>
                          <div>
                            <InputNumber v-model="durCs" :min="0" :max="99" input-class="w-full" />
                            <small>{{ t('events.judgeTiempoCs') }}</small>
                          </div>
                        </div>
                      </div>
                      <div v-if="resultadoEsperado != null" class="field">
                        <label>{{ t('events.judgeResultadoObtenido') }}</label>
                        <InputNumber
                          v-model="resultadoObtenido"
                          :min="0"
                          :max="resultadoEsperado"
                          input-class="w-full"
                        />
                        <small class="pj-muted">
                          {{
                            t('events.judgeResultadoOf', {
                              got: resultadoObtenido ?? 0,
                              expected: resultadoEsperado,
                            })
                          }}
                        </small>
                      </div>
                    </div>

                    <template v-if="!scoreByParticipation && hasCriteria">
                      <div class="criteria-table">
                        <div class="criteria-row criteria-row--head">
                          <span>{{ t('events.criteriaTitle') }}</span>
                          <span>{{ t('events.maxScoreLabel') }}</span>
                          <span>{{ t('events.judgeScoreObtained') }}</span>
                        </div>
                        <div
                          v-for="c in actividad.criterios"
                          :key="c.id"
                          class="criteria-row"
                        >
                          <div>
                            <strong>{{ c.nombre }}</strong>
                            <p v-if="c.descripcion" class="pj-muted">{{ c.descripcion }}</p>
                          </div>
                          <span class="max-pts">{{ c.puntos }}</span>
                          <InputNumber
                            :model-value="criterioScores[c.id] ?? 0"
                            :min="0"
                            :max="Number(c.puntos)"
                            :max-fraction-digits="2"
                            input-class="w-full"
                            @update:model-value="(v) => clampCriterion(c.id, v)"
                          />
                        </div>
                      </div>
                    </template>

                    <div v-else-if="!scoreByParticipation && resultadoEsperado == null" class="generic-score">
                      <p class="pj-muted">{{ t('events.criteriaGenericHint') }}</p>
                      <label>{{ t('events.judgeScoreObtained') }}</label>
                      <InputNumber
                        v-model="genericScore"
                        :min="0"
                        :max="maxScore ?? undefined"
                        :max-fraction-digits="2"
                        input-class="w-full"
                      />
                    </div>

                    <div class="total-row" :class="{ 'total-row--bad': scoreOverflow }">
                      <span>{{ t('events.judgeTotal') }}</span>
                      <strong>
                        {{ scoredTotal }} / {{ maxScore ?? '—' }} pts
                        <small>({{ scorePct }}%)</small>
                      </strong>
                    </div>
                  </section>

                  <section class="evidence-panel">
                    <h3>{{ t('events.clubEvidenceTitle') }}</h3>
                    <p v-if="!(selectedActivityClub?.evidencias?.length)" class="pj-muted">
                      {{ t('events.evidenceEmpty') }}
                    </p>
                    <template v-else>
                      <div class="evidence-tabs">
                        <button
                          v-for="ev in selectedActivityClub.evidencias"
                          :key="ev.id"
                          type="button"
                          class="evidence-tab"
                          :class="{ active: selectedEvidence?.id === ev.id }"
                          @click="selectedEvidenceId = ev.id"
                        >
                          <i
                            :class="{
                              'pi pi-link': ev.tipo === 'link',
                              'pi pi-file-pdf': ev.tipo === 'pdf',
                              'pi pi-image': ev.tipo === 'imagen',
                              'pi pi-volume-up': ev.tipo === 'audio',
                              'pi pi-video': ev.tipo === 'video',
                              'pi pi-file': !['link', 'pdf', 'imagen', 'audio', 'video'].includes(ev.tipo),
                            }"
                          />
                          {{ evidenceLabel(ev) }}
                        </button>
                      </div>

                      <div v-if="selectedEvidence" class="evidence-card">
                        <span class="tipo-chip">{{ selectedEvidence.tipo }}</span>
                        <strong>{{ evidenceLabel(selectedEvidence) }}</strong>
                        <p v-if="selectedEvidence.descripcion" class="pj-muted">
                          {{ selectedEvidence.descripcion }}
                        </p>
                        <a
                          v-if="evidencePreview?.src || selectedEvidence.url"
                          :href="evidencePreview?.src || selectedEvidence.url || undefined"
                          target="_blank"
                          rel="noopener"
                          class="evidence-link"
                        >
                          {{ evidencePreview?.src || selectedEvidence.url }}
                          <i class="pi pi-external-link" />
                        </a>

                        <div v-if="evidencePreview" class="media-box">
                          <div
                            v-if="evidencePreview.kind === 'youtube' || evidencePreview.kind === 'vimeo'"
                            class="media-box__embed"
                          >
                            <iframe
                              :src="evidencePreview.embedSrc"
                              title="video"
                              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                              allowfullscreen
                            />
                          </div>
                          <img
                            v-else-if="evidencePreview.kind === 'image'"
                            :src="evidencePreview.src"
                            :alt="evidenceLabel(selectedEvidence)"
                          />
                          <video
                            v-else-if="evidencePreview.kind === 'video'"
                            :src="evidencePreview.src"
                            controls
                            preload="metadata"
                          />
                          <audio
                            v-else-if="evidencePreview.kind === 'audio'"
                            :src="evidencePreview.src"
                            controls
                            preload="metadata"
                          />
                          <iframe
                            v-else-if="evidencePreview.kind === 'pdf'"
                            :src="evidencePreview.embedSrc || evidencePreview.src"
                            title="pdf"
                          />
                          <div v-else class="link-fallback">
                            <i class="pi pi-link" />
                            <div>
                              <strong>{{ evidencePreview.title || evidencePreview.host }}</strong>
                              <p class="pj-muted truncate">{{ evidencePreview.src }}</p>
                            </div>
                            <a
                              :href="evidencePreview.src"
                              target="_blank"
                              rel="noopener"
                              class="open-link"
                            >
                              {{ t('events.judgeOpenEvidence') }}
                              <i class="pi pi-external-link" />
                            </a>
                          </div>
                        </div>
                      </div>
                    </template>
                  </section>
                </div>
              </template>
              <p v-else class="pj-muted empty">{{ t('events.judgeSelectClub') }}</p>
            </template>
          </EventJudgeActivityCard>
        </template>

        <p v-else class="pj-muted empty">{{ t('events.judgeSelectEvent') }}</p>

        <template #footer>
          <Button
            type="button"
            text
            :label="t('common.cancel')"
            @click="drawerVisible = false"
          />
          <Button
            type="button"
            outlined
            icon="pi pi-save"
            :label="t('common.save')"
            :loading="saving"
            :disabled="!selectedOrgId || !actividad || !canScoreActivity"
            @click="saveAndMaybeNext(false)"
          />
          <Button
            type="button"
            icon="pi pi-arrow-right"
            :label="t('events.judgeSaveNext')"
            :loading="saving"
            :disabled="!selectedOrgId || !actividad || !canScoreActivity"
            @click="saveAndMaybeNext(true)"
          />
        </template>
      </AppStackDrawer>
    </template>
  </div>
</template>

<style scoped>
.judge-page {
  --judge-sheet-gap: calc(4.75rem + env(safe-area-inset-top, 0px));
  display: grid;
  gap: 1rem;
}

.judge-top {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  flex-wrap: wrap;
  padding: 1.1rem 1.2rem;
  border-radius: 16px;
  overflow: visible;
  isolation: isolate;
}

.judge-top__intro {
  display: flex;
  align-items: center;
  gap: 0.95rem;
  min-width: 0;
  flex: 1;
}

.judge-top__logo {
  width: 4.5rem;
  height: 4.5rem;
  flex: 0 0 auto;
  object-fit: cover;
  border-radius: 0.9rem;
  border: 3px solid #fff;
  background: #fff;
  box-shadow: 0 10px 22px rgba(15, 23, 42, 0.28);
}

.judge-top.has-cover.has-logo {
  overflow: visible;
  margin-bottom: 2.4rem;
  align-items: flex-end;
}

.judge-top.has-cover.has-logo .judge-top__intro {
  align-items: flex-end;
  align-self: flex-end;
}

.judge-top.has-cover.has-logo .judge-top__logo {
  position: relative;
  z-index: 2;
  margin-bottom: -2.4rem;
}

.judge-top.has-cover {
  min-height: 7.25rem;
  color: var(--hero-text, #fff);
  background-image:
    var(--hero-overlay, linear-gradient(180deg, rgba(7, 18, 42, 0.28) 0%, rgba(7, 18, 42, 0.78) 100%)),
    var(--hero-image);
  background-size: cover;
  background-position: center;
}

.judge-top.has-cover .pj-muted {
  color: var(--hero-muted, rgba(255, 255, 255, 0.82));
}

.judge-top.has-cover :deep(.p-button.p-button-outlined) {
  color: var(--hero-btn-text, #fff);
  background: var(--hero-btn-bg, rgba(15, 23, 42, 0.35));
  border-color: var(--hero-btn-border, rgba(255, 255, 255, 0.38));
}

.judge-top.has-cover :deep(.p-button.p-button-outlined:hover) {
  background: color-mix(in srgb, var(--hero-btn-bg, rgba(15, 23, 42, 0.35)) 80%, #fff);
}

.judge-top.has-cover .progress-pill,
.judge-top.has-cover .max-chip {
  color: var(--hero-chip-text, #fff);
  background: var(--hero-chip-bg, rgba(15, 23, 42, 0.48));
  border-color: var(--hero-chip-border, rgba(255, 255, 255, 0.28));
}

.judge-top.has-cover .progress-pill span,
.judge-top.has-cover .max-chip span {
  color: var(--hero-chip-muted, rgba(255, 255, 255, 0.78));
}

.judge-top.has-cover h1 {
  text-shadow: 0 1px 12px color-mix(in srgb, var(--hero-chip-bg, rgba(15, 23, 42, 0.5)) 70%, transparent);
}

.judge-top h1 {
  margin: 0.15rem 0 0.2rem;
  font-size: 1.45rem;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}

.judge-top.has-cover.has-logo .judge-top__meta {
  align-self: flex-start;
}

.judge-top__meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.55rem;
  margin-left: auto;
  flex-shrink: 0;
}

.judge-top__stats {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.55rem;
}

.judge-toolbar .field {
  min-width: min(100%, 22rem);
  flex: 1;
}

.judge-toolbar {
  display: flex;
  gap: 0.85rem;
  align-items: flex-end;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.judge-toolbar .field {
  min-width: min(100%, 22rem);
  flex: 1;
}

.judge-toolbar label {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.8rem;
  font-weight: 650;
}

.selection-crumb {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  margin-bottom: 0.75rem;
  font-size: 0.86rem;
  color: var(--pj-text-muted, #64748b);
}

.selection-crumb strong {
  color: var(--pj-text, #0f172a);
}

.selection-crumb small {
  margin-left: 0.35rem;
  font-weight: 700;
}

.progress-pill,
.max-chip {
  padding: 0.65rem 0.85rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: color-mix(in srgb, var(--pj-bg) 70%, transparent);
}

.progress-pill strong,
.max-chip strong {
  display: block;
}

.progress-pill span,
.max-chip span {
  font-size: 0.72rem;
  color: var(--pj-text-muted);
}

.judge-layout {
  display: grid;
  grid-template-columns: minmax(280px, 380px) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}

.panel {
  border-radius: 16px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  background: color-mix(in srgb, var(--pj-surface, #fff) 94%, transparent);
  padding: 0.9rem;
}

.panel--list,
.panel--tree {
  display: grid;
  gap: 0.55rem;
  max-height: calc(100vh - 12rem);
  overflow: auto;
  align-content: start;
}

.drawer-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  padding: 2rem 1rem;
  color: var(--pj-text-muted, #64748b);
  font-size: 0.9rem;
}

.drawer-club-head {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  min-width: 0;
}

.drawer-club-head .club-item__avatar {
  background: rgb(255 255 255 / 0.16);
  color: #fff;
  box-shadow: 0 0 0 1px rgb(255 255 255 / 0.28);
}

.drawer-club-head__text {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.drawer-club-head__text strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.drawer-event-line {
  opacity: 0.85;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.club-switcher {
  display: grid;
  gap: 0.65rem;
  padding: 0.85rem;
  margin-bottom: 0.35rem;
  border-radius: 14px;
  border: 1px solid color-mix(in srgb, #2563eb 22%, transparent);
  background: color-mix(in srgb, #2563eb 12%, var(--pj-surface));
  position: sticky;
  top: 0;
  z-index: 2;
}

.club-switcher__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
}

.club-switcher__score {
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--pj-text-muted, #64748b);
}

.club-switcher__nav {
  display: grid;
  gap: 0.55rem;
  align-items: center;
}

.club-switcher__count {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.55rem;
  min-width: 0;
  font-size: 0.78rem;
  font-weight: 700;
  color: #1d4ed8;
}

.club-switcher__parent {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.club-switcher__index {
  flex-shrink: 0;
  white-space: nowrap;
  opacity: 0.8;
}

.club-switcher__select {
  width: 100%;
}

.club-switcher__arrows {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  justify-content: flex-end;
}

.tree-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.55rem;
  position: sticky;
  top: 0;
  background: inherit;
  z-index: 1;
  padding-bottom: 0.35rem;
}

.tree-head .phase-chip {
  flex: 1;
  min-width: 0;
}

.tree-head strong {
  font-size: 0.95rem;
}

.tree-head p {
  margin: 0;
  font-size: 0.75rem;
}

.list-head {
  display: grid;
  gap: 0.55rem;
  position: sticky;
  top: 0;
  background: inherit;
  z-index: 1;
  padding-bottom: 0.35rem;
}

.filter-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.filter-tabs button {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: transparent;
  border-radius: 999px;
  padding: 0.28rem 0.65rem;
  font-size: 0.75rem;
  font-weight: 650;
  cursor: pointer;
}

.filter-tabs button.active {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  border-color: #2563eb;
  color: #1d4ed8;
}

.club-item {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 0.65rem;
  align-items: center;
  width: 100%;
  text-align: left;
  padding: 0.65rem;
  border-radius: 12px;
  border: 1px solid transparent;
  background: transparent;
  cursor: pointer;
}

.club-item:hover,
.club-item.active {
  background: color-mix(in srgb, #2563eb 8%, transparent);
  border-color: color-mix(in srgb, #2563eb 25%, transparent);
}

.club-item__avatar {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 10px;
  overflow: hidden;
  display: grid;
  place-items: center;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
  flex-shrink: 0;
}

.club-item__avatar.lg {
  width: 3.25rem;
  height: 3.25rem;
}

.club-item__avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.club-item__body {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.club-item__body strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.club-item__score {
  text-align: right;
  font-size: 0.82rem;
}

.club-item__score small {
  display: block;
  color: var(--pj-text-muted);
}

.club-item__pending {
  display: grid;
  justify-items: end;
  align-content: center;
  gap: 0.2rem;
  min-width: 3.2rem;
}

.pending-badge {
  display: inline-grid;
  place-items: center;
  min-width: 1.7rem;
  height: 1.7rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 800;
  background: color-mix(in srgb, #ea580c 18%, transparent);
  color: #c2410c;
  border: 1px solid color-mix(in srgb, #ea580c 35%, transparent);
}

.phase-chip {
  display: flex;
  gap: 0.65rem;
  align-items: flex-start;
  margin-bottom: 0.35rem;
}

.phase-chip__step {
  width: 1.55rem;
  height: 1.55rem;
  border-radius: 999px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  font-size: 0.78rem;
  font-weight: 800;
  background: color-mix(in srgb, #94a3b8 20%, transparent);
  color: #475569;
}

.phase-chip__step.is-ready {
  background: color-mix(in srgb, #0f766e 20%, transparent);
  color: #0f766e;
}

.phase-chip strong {
  display: block;
  font-size: 0.88rem;
}

.phase-chip .pj-muted {
  margin: 0.1rem 0 0;
  font-size: 0.75rem;
  line-height: 1.3;
}

.panel--tree.is-locked {
  opacity: 0.72;
  background: color-mix(in srgb, var(--pj-surface) 88%, var(--pj-bg-muted));
}

.tree-lock {
  display: grid;
  place-items: center;
  gap: 0.55rem;
  min-height: 12rem;
  padding: 1.5rem 1rem;
  text-align: center;
  color: var(--pj-text-muted, #64748b);
  border: 1px dashed color-mix(in srgb, var(--pj-border) 80%, transparent);
  border-radius: 12px;
}

.tree-lock i {
  font-size: 1.4rem;
}

.tree-body {
  min-height: 0;
  overflow: auto;
}

.status-badge {
  display: inline-flex;
  align-self: flex-start;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  font-size: 0.68rem;
  font-weight: 700;
}

.status-badge.is-scored {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.status-badge.is-pending {
  background: color-mix(in srgb, #ca8a04 16%, transparent);
  color: #a16207;
}

.detail-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  align-items: center;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.detail-head__main {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  min-width: 0;
}

.detail-head h2 {
  margin: 0;
  font-size: 1.2rem;
}

.nav-clubs {
  display: flex;
  gap: 0.25rem;
  flex-wrap: wrap;
}

.workspace {
  display: grid;
  grid-template-columns: 1.2fr 0.9fr;
  gap: 1rem;
  margin-top: 1rem;
}

.score-panel,
.evidence-panel {
  display: grid;
  gap: 0.75rem;
  align-content: start;
}

.score-panel h3,
.evidence-panel h3 {
  margin: 0;
  font-size: 1rem;
}

.criteria-table {
  display: grid;
  gap: 0.45rem;
}

.criteria-row {
  display: grid;
  grid-template-columns: 1.4fr 0.6fr 0.8fr;
  gap: 0.55rem;
  align-items: center;
  padding: 0.55rem 0.35rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 55%, transparent);
}

.criteria-row--head {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--pj-text-muted);
}

.max-pts {
  font-weight: 700;
  text-align: center;
}

.generic-score {
  display: grid;
  gap: 0.35rem;
}

.total-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.7rem 0.85rem;
  border-radius: 12px;
  background: color-mix(in srgb, #2563eb 8%, transparent);
  border: 1px solid color-mix(in srgb, #2563eb 20%, transparent);
}

.total-row--bad {
  background: color-mix(in srgb, #dc2626 10%, transparent);
  border-color: color-mix(in srgb, #dc2626 28%, transparent);
  color: #b91c1c;
}

.total-row small {
  margin-left: 0.35rem;
  font-weight: 600;
  opacity: 0.8;
}

.field label {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.8rem;
  font-weight: 650;
}

.director-obs-box {
  margin-top: 0.85rem;
  padding: 0.75rem 0.85rem;
  border-radius: 12px;
  background: color-mix(in srgb, #0f766e 8%, transparent);
  border: 1px solid color-mix(in srgb, #0f766e 22%, transparent);
}

.director-obs-box h4 {
  margin: 0 0 0.35rem;
  font-size: 0.82rem;
  color: #0f766e;
}

.director-obs-box p {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.45;
  color: #334155;
  white-space: pre-wrap;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.evidence-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.evidence-tab {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.65rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: transparent;
  cursor: pointer;
  font-size: 0.78rem;
  font-weight: 650;
}

.evidence-tab.active {
  border-color: #2563eb;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.evidence-card {
  display: grid;
  gap: 0.4rem;
  padding: 0.85rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  background: color-mix(in srgb, var(--pj-bg) 70%, transparent);
}

.tipo-chip {
  justify-self: start;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
}

.evidence-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.84rem;
  word-break: break-all;
}

.media-box {
  margin-top: 0.35rem;
  border-radius: 10px;
  overflow: hidden;
  background: color-mix(in srgb, #0f172a 6%, transparent);
}

.media-box__embed {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: #0f172a;
}

.media-box__embed iframe {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.media-box img,
.media-box video {
  display: block;
  width: 100%;
  max-height: 280px;
  object-fit: contain;
}

.media-box audio {
  width: 100%;
  padding: 0.75rem;
}

.media-box iframe {
  width: 100%;
  height: 240px;
  border: 0;
}

.link-fallback {
  display: grid;
  gap: 0.55rem;
  place-items: center;
  padding: 1.25rem 1rem;
  text-align: center;
  color: #2563eb;
}

.link-fallback > i {
  font-size: 1.6rem;
}

.link-fallback .truncate {
  margin: 0.2rem 0 0;
  max-width: 18rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.open-link {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 650;
  color: #1d4ed8;
}

.grading-extras {
  display: grid;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.grading-extras .field {
  display: grid;
  gap: 0.3rem;
}

.duration-input {
  display: flex;
  align-items: end;
  gap: 0.4rem;
}

.duration-input > div {
  display: grid;
  gap: 0.2rem;
  min-width: 4.5rem;
}

.duration-input span {
  padding-bottom: 1.15rem;
  font-weight: 700;
}

.duration-input small {
  color: var(--pj-text-muted, #64748b);
  font-size: 0.72rem;
}

.judge-roster {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.45rem;
}

.judge-roster li {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.75rem;
  align-items: baseline;
  padding: 0.55rem 0.7rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
}

.judge-roster small {
  color: var(--pj-text-muted, #64748b);
}

.empty {
  margin: 1rem 0;
}

@media (max-width: 900px) {
  .judge-layout,
  .workspace {
    grid-template-columns: 1fr;
  }

  .panel--list {
    max-height: none;
  }

  .tree-sheet-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1080;
    background: rgb(15 23 42 / 0.38);
  }

  .panel--tree.is-sheet {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 1081;
    display: flex;
    flex-direction: column;
    width: 100%;
    height: calc(100dvh - var(--judge-sheet-gap));
    max-height: calc(100dvh - var(--judge-sheet-gap));
    margin: 0;
    padding: 0.55rem 0.9rem calc(0.9rem + env(safe-area-inset-bottom, 0px));
    overflow: hidden;
    border-radius: 18px 18px 0 0;
    box-shadow: 0 -10px 32px rgb(15 23 42 / 0.22);
    transform: translateY(110%);
    transition: transform 0.28s ease;
    pointer-events: none;
  }

  .panel--tree.is-sheet-open {
    transform: translateY(0);
    pointer-events: auto;
  }

  .panel--tree.is-sheet .tree-head {
    flex-shrink: 0;
  }

  .panel--tree.is-sheet .tree-lock,
  .panel--tree.is-sheet .tree-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
  }

  .tree-sheet__handle {
    width: 2.6rem;
    height: 0.28rem;
    margin: 0.15rem auto 0.45rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--pj-border, #cbd5e1) 80%, #94a3b8);
    flex-shrink: 0;
  }

  .tree-sheet__close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 8px;
    background: color-mix(in srgb, #1e3a8a 10%, transparent);
    color: #1e3a8a;
    cursor: pointer;
    flex-shrink: 0;
  }

  .tree-reopen {
    position: sticky;
    bottom: 0.65rem;
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    width: 100%;
    padding: 0.75rem 1rem;
    border: 0;
    border-radius: 14px;
    background: #1e3a8a;
    color: #fff;
    font-weight: 700;
    box-shadow: 0 8px 22px rgb(30 58 138 / 0.28);
  }
}
</style>
