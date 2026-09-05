<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'
import ToggleSwitch from 'primevue/toggleswitch'
import Message from 'primevue/message'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import EventSubeventTreeNodes from '@/components/events/EventSubeventTreeNodes.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import MediaCoverUpload from '@/components/media/MediaCoverUpload.vue'
import EventSubeventConfigTabs from '@/components/events/EventSubeventConfigTabs.vue'
import JuezPropagateDialog from '@/components/events/JuezPropagateDialog.vue'
import { useJuezPropagate } from '@/composables/useJuezPropagate'
import RichTextField from '@/components/RichTextField.vue'
import RichTextView from '@/components/RichTextView.vue'
import IconColorPopover from '@/components/IconColorPopover.vue'
import IconMark from '@/components/IconMark.vue'
import { cssColor } from '@/utils/color'
import { clampIconSize, ICON_SIZE_DEFAULT } from '@/utils/iconSize'
import { iconBoxStyle, resolveEventIconColor } from '@/utils/iconVisual'
import { normalizeRichText } from '@/utils/richText'
import type {
  CategoriaSubevento,
  ClubEvent,
  CriterioEvaluacion,
  EventoArchivoMaterial,
  EventoVisibilidad,
  TipoEvento,
} from '@/modules/events/types'
import {
  addOneDay,
  dateOnly,
  ensureEndAfterStart,
  formatDateOnly,
  toApiDate,
} from '@/modules/events/dateUtils'

type NavNode = {
  id: number
  name: string
  starts_at: string
  ends_at: string
  es_en_sitio: boolean
  maneja_fecha_fin: boolean
  organizacion_id: number | null
  puntaje_maximo: number | null
  tipo_evento_id: number | null
  visibilidad: EventoVisibilidad
  juez_ids: number[]
  supervisor_ids: number[]
}

const props = defineProps<{
  parentId: number | null
  parentName: string
  parentPuntajeMaximo: number | null
  parentStartsAt: Date | null
  parentEndsAt: Date | null
  parentOrganizacionId: number | null
  parentEsEnSitio: boolean
  parentVisibilidad: EventoVisibilidad
  parentJuezIds?: number[]
  parentSupervisorIds?: number[]
  categoriasVersion?: number
  parentCategoriaIds?: number[]
  parentCriterioIds?: number[]
}>()

const emit = defineEmits<{
  changed: []
}>()

const { t } = useI18n()
const toast = useToast()
const {
  visible: juezConflictVisible,
  applying: juezConflictApplying,
  conflicts: juezConflicts,
  offer: offerJuezConflicts,
  apply: applyJuezConflicts,
  dismiss: dismissJuezConflicts,
} = useJuezPropagate()

const loading = ref(false)
const saving = ref(false)
const items = ref<ClubEvent[]>([])
const categorias = ref<CategoriaSubevento[]>([])
const tiposEvento = ref<TipoEvento[]>([])
const jueces = ref<Array<{ id: number; name: string; email?: string | null }>>([])
const supervisores = ref<Array<{ id: number; name: string; email?: string | null }>>([])
const criteriosBank = ref<CriterioEvaluacion[]>([])
const assignedCriterioIds = ref<number[]>([])
const criterioPoints = reactive<Record<number, number | null>>({})
const childAssignedCriterioIds = ref<number[]>([])
const childCriterioPoints = reactive<Record<number, number | null>>({})
const search = ref('')
const selectedId = ref<number | null>(null)
const rowMenu = ref<{ toggle: (event: Event) => void } | null>(null)
const rowMenuTarget = ref<ClubEvent | null>(null)
const detailTab = ref<'info' | 'reglas' | 'puntaje' | 'categoria'>('info')
const materiales = ref<EventoArchivoMaterial[]>([])
const pendingMaterialFiles = ref<File[]>([])
const pendingYoutube = ref<Array<{ url: string; titulo?: string }>>([])
const childDrawerVisible = ref(false)
const childItems = ref<ClubEvent[]>([])
const loadingChildren = ref(false)
const drawerVisible = ref(false)
const editingId = ref<number | null>(null)
const editingParentId = ref<number | null>(null)
const dragFrom = ref<number | null>(null)
const dragId = ref<number | null>(null)
const dropTarget = ref<{ id: number; mode: 'before' | 'after' | 'into' } | null>(null)
const moving = ref(false)
const pulseId = ref<number | null>(null)
let dropRaf = 0
let pulseTimer: ReturnType<typeof setTimeout> | undefined
let dragGhostEl: HTMLElement | null = null
const errorMessage = ref('')
const navStack = ref<NavNode[]>([])
const expandedChildren = ref<Set<number>>(new Set())
const pendingImage = ref<File | null>(null)
const pendingPreview = ref<string | null>(null)
const formImageUrl = ref<string | null>(null)
const uploadingImage = ref(false)

const activeParentId = computed(() => navStack.value.at(-1)?.id ?? props.parentId)
const activeParentName = computed(() => navStack.value.at(-1)?.name ?? props.parentName)
const isNested = computed(() => navStack.value.length > 0)
const contextTipoEventoId = computed(() => navStack.value.at(-1)?.tipo_evento_id ?? null)
const contextVisibilidad = computed(
  () => navStack.value.at(-1)?.visibilidad ?? props.parentVisibilidad,
)

const form = reactive({
  name: '',
  descripcion: '',
  reglas: '',
  categoria_subevento_id: null as number | null,
  tipo_evento_id: null as number | null,
  puntaje_maximo: null as number | null,
  puntaje_por_participar: false,
  starts_at: null as Date | null,
  ends_at: null as Date | null,
  tiempo_estimado_minutos: null as number | null,
  requiere_puesto_entrega: false,
  requiere_tiempo_entrega: false,
  resultado_esperado: null as number | null,
  participantes_min: null as number | null,
  participantes_max: null as number | null,
  permite_inscribir_no_participantes: false,
  participantes_genero: 'cualquiera' as 'mixto' | 'M' | 'F' | 'cualquiera',
  participantes_min_m: null as number | null,
  participantes_max_m: null as number | null,
  participantes_min_f: null as number | null,
  participantes_max_f: null as number | null,
  es_conjunto: false,
  nivel_conjunto: null as 'club' | 'iglesia' | 'distrito' | 'asociacion' | null,
  puntos_penalizacion: null as number | null,
  reglas_penalizacion: '',
  precio: null as number | null,
  tipos_evidencia: [] as Array<'link' | 'pdf' | 'imagen' | 'audio' | 'video'>,
  estado: 'publicado' as string,
  visibilidad: 'organizacion' as EventoVisibilidad,
  juez_ids: [] as number[],
  supervisor_ids: [] as number[],
  icono: 'pi pi-calendar',
  color: '#1e3a5f',
  icono_tamano: ICON_SIZE_DEFAULT,
})

const visualKind = ref<'imagen' | 'icono'>('icono')

/** Controles progresivos: solo muestran campos cuando están activos */
const opts = reactive({
  manejaPuntaje: false,
  puntajeDesdeHijos: false,
  configCalificacion: false,
  controlParticipantes: false,
  esConjunto: false,
  manejaFechaFin: false,
  manejaPenalizaciones: false,
  tieneValor: false,
  requiereEvidencia: false,
  esEnSitio: true,
  tieneSubeventos: false,
})

const childrenScoreSum = ref(0)
const loadingChildrenScore = ref(false)

const estadoOptions = computed(() => [
  { label: t('events.estadoPublicado'), value: 'publicado' },
  { label: t('events.estadoBorrador'), value: 'borrador' },
])

const participantesGeneroOptions = computed(() => [
  { label: t('events.wizard.subParticipantsGenderCualquiera'), value: 'cualquiera' },
  { label: t('events.wizard.subParticipantsGenderMixto'), value: 'mixto' },
  { label: t('events.wizard.subParticipantsGenderM'), value: 'M' },
  { label: t('events.wizard.subParticipantsGenderF'), value: 'F' },
])

const nivelConjuntoOptions = computed(() => [
  { label: t('events.wizard.subNivelClub'), value: 'club' },
  { label: t('events.wizard.subNivelIglesia'), value: 'iglesia' },
  { label: t('events.wizard.subNivelDistrito'), value: 'distrito' },
  { label: t('events.wizard.subNivelAsociacion'), value: 'asociacion' },
])

const juezOptions = computed(() =>
  jueces.value.map((j) => ({
    ...j,
    label: j.email ? `${j.name} (${j.email})` : j.name,
  })),
)

const supervisorOptions = computed(() =>
  supervisores.value.map((s) => ({
    ...s,
    label: s.email ? `${s.name} (${s.email})` : s.name,
  })),
)

function peopleNames(
  people: Array<{ id: number; name: string }> | null | undefined,
  emptyLabel: string,
): string {
  if (!people?.length) return emptyLabel
  return people.map((p) => p.name).join(', ')
}

function quotaRange(min?: number | null, max?: number | null): string {
  if (min == null && max == null) return '—'
  if (max == null) return String(min ?? '—')
  return `${min ?? '—'}–${max}`
}

function participantesQuotaLabel(item: {
  participantes_genero?: string | null
  participantes_min?: number | null
  participantes_max?: number | null
  participantes_min_m?: number | null
  participantes_max_m?: number | null
  participantes_min_f?: number | null
  participantes_max_f?: number | null
}): string {
  if (item.participantes_genero === 'M') {
    return `${quotaRange(item.participantes_min, item.participantes_max)} · ${t('events.wizard.subParticipantsGenderM')}`
  }
  if (item.participantes_genero === 'F') {
    return `${quotaRange(item.participantes_min, item.participantes_max)} · ${t('events.wizard.subParticipantsGenderF')}`
  }
  if (item.participantes_genero === 'mixto') {
    return `${t('events.wizard.subParticipantsGenderMixto')} · M ${quotaRange(item.participantes_min_m, item.participantes_max_m)} / F ${quotaRange(item.participantes_min_f, item.participantes_max_f)}`
  }
  if (item.participantes_genero === 'cualquiera') {
    return `${quotaRange(item.participantes_min, item.participantes_max)} · ${t('events.wizard.subParticipantsGenderCualquiera')}`
  }
  return quotaRange(item.participantes_min, item.participantes_max)
}

const evidenciaTipoOptions = computed(() => [
  { key: 'link' as const, label: t('events.wizard.subEvidenceLink') },
  { key: 'pdf' as const, label: t('events.wizard.subEvidencePdf') },
  { key: 'imagen' as const, label: t('events.wizard.subEvidenceImage') },
  { key: 'audio' as const, label: t('events.wizard.subEvidenceAudio') },
  { key: 'video' as const, label: t('events.wizard.subEvidenceVideo') },
])

const categoriasDisponibles = computed(() => {
  const allowed = props.parentCategoriaIds ?? []
  if (!allowed.length) return categorias.value
  const set = new Set(allowed)
  return categorias.value.filter((item) => set.has(item.id))
})

const criterioOptions = computed(() => {
  const allowed = props.parentCriterioIds ?? []
  const extra = new Set([...assignedCriterioIds.value, ...childAssignedCriterioIds.value])
  const bank = !allowed.length
    ? criteriosBank.value
    : criteriosBank.value.filter((item) => allowed.includes(item.id) || extra.has(item.id))
  return bank.map((c) => ({
    ...c,
    label: c.nombre,
  }))
})

const criteriosSum = computed(() =>
  assignedCriterioIds.value.reduce((sum, id) => sum + (Number(criterioPoints[id]) || 0), 0),
)

const criteriosSumOk = computed(() => {
  if (!assignedCriterioIds.value.length) return true
  if (form.puntaje_maximo == null) return false
  return Math.abs(criteriosSum.value - Number(form.puntaje_maximo)) < 0.01
})

function syncCriterioPointsSelection(): void {
  for (const id of assignedCriterioIds.value) {
    if (criterioPoints[id] == null) criterioPoints[id] = null
  }
  for (const key of Object.keys(criterioPoints)) {
    const id = Number(key)
    if (!assignedCriterioIds.value.includes(id)) delete criterioPoints[id]
  }
}

function toggleEvidenciaTipo(tipo: 'link' | 'pdf' | 'imagen' | 'audio' | 'video'): void {
  const set = new Set(form.tipos_evidencia)
  if (set.has(tipo)) set.delete(tipo)
  else set.add(tipo)
  form.tipos_evidencia = [...set]
}

function evidenciaTiposLabel(tipos: string[] | null | undefined): string {
  if (!tipos?.length) return '—'
  return tipos
    .map((tipo) => evidenciaTipoOptions.value.find((o) => o.key === tipo)?.label || tipo)
    .join(', ')
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return items.value
  return items.value.filter(
    (item) =>
      item.name.toLowerCase().includes(q) ||
      (item.descripcion || '').toLowerCase().includes(q) ||
      (item.categoria_subevento?.nombre || '').toLowerCase().includes(q) ||
      (item.tipo_evento?.nombre || '').toLowerCase().includes(q),
  )
})

const selected = computed(() => findInTree(items.value, selectedId.value))

function findInTree(nodes: ClubEvent[], id: number | null): ClubEvent | null {
  if (id == null) return null
  for (const node of nodes) {
    if (node.id === id) return node
    if (node.hijos?.length) {
      const found = findInTree(node.hijos, id)
      if (found) return found
    }
  }
  return null
}

function cloneNode(node: ClubEvent): ClubEvent {
  return {
    ...node,
    hijos: node.hijos?.map(cloneNode) ?? node.hijos,
  }
}

function removeFromTree(
  nodes: ClubEvent[],
  id: number,
): { tree: ClubEvent[]; removed: ClubEvent | null; oldParentId: number | null } {
  let removed: ClubEvent | null = null
  let oldParentId: number | null = null
  const tree: ClubEvent[] = []

  for (const node of nodes) {
    if (node.id === id) {
      removed = cloneNode(node)
      oldParentId = node.evento_padre_id ?? null
      continue
    }
    if (node.hijos?.length) {
      const child = removeFromTree(node.hijos, id)
      if (child.removed) {
        removed = child.removed
        oldParentId = child.oldParentId
        const hijos = child.tree
        tree.push({ ...node, hijos, hijos_count: hijos.length })
        continue
      }
    }
    tree.push(node)
  }

  return { tree, removed, oldParentId }
}

function insertAmong(
  siblings: ClubEvent[],
  node: ClubEvent,
  beforeId: number | null,
  parentId: number,
): ClubEvent[] {
  const placed: ClubEvent = { ...node, evento_padre_id: parentId }
  const without = siblings.filter((s) => s.id !== placed.id)
  let next: ClubEvent[]
  if (beforeId == null) {
    next = [...without, placed]
  } else {
    const idx = without.findIndex((s) => s.id === beforeId)
    next =
      idx < 0
        ? [...without, placed]
        : [...without.slice(0, idx), placed, ...without.slice(idx)]
  }
  return next.map((s, i) => ({ ...s, orden: i + 1 }))
}

function insertIntoTree(
  nodes: ClubEvent[],
  parentId: number,
  node: ClubEvent,
  beforeId: number | null,
  rootParentId: number,
): ClubEvent[] {
  if (parentId === rootParentId) {
    return insertAmong(nodes, node, beforeId, parentId)
  }
  return nodes.map((n) => {
    if (n.id === parentId) {
      const hijos = insertAmong(n.hijos ?? [], node, beforeId, parentId)
      return { ...n, hijos, hijos_count: hijos.length }
    }
    if (n.hijos?.length) {
      return {
        ...n,
        hijos: insertIntoTree(n.hijos, parentId, node, beforeId, rootParentId),
      }
    }
    return n
  })
}

function applyLocalMove(
  source: ClubEvent[],
  movedId: number,
  newParentId: number,
  beforeId: number | null,
  rootParentId: number,
): ClubEvent[] | null {
  const { tree, removed } = removeFromTree(source, movedId)
  if (!removed) return null
  return insertIntoTree(tree, newParentId, removed, beforeId, rootParentId)
}

function childCount(item: ClubEvent): number {
  return item.hijos_count ?? item.hijos?.length ?? 0
}

function hasChildren(item: ClubEvent): boolean {
  return childCount(item) > 0
}

function isExpanded(id: number): boolean {
  return expandedChildren.value.has(id)
}

function toggleChildren(id: number): void {
  const next = new Set(expandedChildren.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expandedChildren.value = next
}

function selectItem(item: ClubEvent): void {
  selectedId.value = item.id
  detailTab.value = 'info'
}

const rowMenuItems = computed<MenuItem[]>(() => {
  const item = rowMenuTarget.value
  if (!item) return []
  const items: MenuItem[] = [
    {
      label: t('events.wizard.subAddChild'),
      icon: 'pi pi-plus',
      command: () => openCreateChild(item),
    },
    {
      label: t('events.wizard.subOpenChildren'),
      icon: 'pi pi-sitemap',
      command: () => enterChildren(item),
    },
    {
      label: t('common.edit'),
      icon: 'pi pi-pencil',
      command: () => openEdit(item),
    },
    {
      label: t('events.wizard.subDuplicate'),
      icon: 'pi pi-copy',
      command: () => void duplicateSubevent(item),
    },
  ]
  if (!hasChildren(item)) {
    items.push({
      label: t('common.delete'),
      icon: 'pi pi-trash',
      command: () => removeSubevent(item),
    })
  }
  return items
})

async function toggleRowMenu(item: ClubEvent, event: Event): Promise<void> {
  event.stopPropagation()
  rowMenuTarget.value = item
  await nextTick()
  rowMenu.value?.toggle(event)
}

const stats = computed(() => {
  const total = items.value.length
  const puntos = items.value.reduce((sum, i) => sum + Number(i.puntaje_maximo || 0), 0)
  const activos = items.value.filter((i) => i.estado === 'publicado').length
  const borradores = items.value.filter((i) => i.estado === 'borrador').length
  return { total, puntos, activos, borradores }
})

const budget = computed(() => {
  const nested = navStack.value.at(-1)?.puntaje_maximo
  if (nested != null) return Number(nested)
  return Number(props.parentPuntajeMaximo || 0)
})
const budgetOk = computed(() => !budget.value || stats.value.puntos <= budget.value)

const selectedCategoria = computed(
  () =>
    categoriasDisponibles.value.find((c) => c.id === form.categoria_subevento_id) ||
    categorias.value.find((c) => c.id === form.categoria_subevento_id) ||
    null,
)

const drawerTitle = computed(() =>
  editingId.value ? t('events.wizard.subEdit') : t('events.wizard.subAdd'),
)
const drawerSubtitle = computed(() =>
  t('events.wizard.subDrawerUnder', { name: activeParentName.value }),
)

const imagePreview = computed(() => pendingPreview.value || formImageUrl.value)

function revokeUrl(url: string | null): void {
  if (url) URL.revokeObjectURL(url)
}

function clearPendingImage(): void {
  pendingImage.value = null
  revokeUrl(pendingPreview.value)
  pendingPreview.value = null
}

async function onPickImage(file: File): Promise<void> {
  if (!file) return
  pendingImage.value = file
  revokeUrl(pendingPreview.value)
  pendingPreview.value = URL.createObjectURL(file)
}

function staffIdsFromEvent(
  ids: number[] | undefined,
  people?: Array<{ id: number }> | null,
  effective?: Array<{ id: number }> | null,
): number[] {
  if (ids?.length) return [...ids]
  if (effective?.length) return effective.map((person) => person.id)
  if (people?.length) return people.map((person) => person.id)
  return []
}

function toNavNode(item: ClubEvent): NavNode {
  return {
    id: item.id,
    name: item.name,
    starts_at: item.starts_at,
    ends_at: item.ends_at,
    es_en_sitio: item.es_en_sitio ?? true,
    maneja_fecha_fin: !!item.maneja_fecha_fin,
    organizacion_id: item.organizacion_id ?? props.parentOrganizacionId,
    puntaje_maximo: item.puntaje_maximo ?? null,
    tipo_evento_id: item.tipo_evento_id ?? null,
    visibilidad: item.visibilidad,
    juez_ids: staffIdsFromEvent(item.juez_ids, item.jueces, item.jueces_efectivos),
    supervisor_ids: staffIdsFromEvent(
      item.supervisor_ids,
      item.supervisores,
      item.supervisores_efectivos,
    ),
  }
}

function contextStartsAt(): Date | null {
  const node = navStack.value.at(-1)
  if (node) {
    return dateOnly(node.starts_at) || props.parentStartsAt
  }
  return props.parentStartsAt
}

function contextEndsAt(): Date | null {
  const node = navStack.value.at(-1)
  if (node) {
    return dateOnly(node.ends_at) || props.parentEndsAt
  }
  return props.parentEndsAt
}

function contextOrganizacionId(): number | null {
  return navStack.value.at(-1)?.organizacion_id ?? props.parentOrganizacionId
}

function contextEsEnSitio(): boolean {
  return navStack.value.at(-1)?.es_en_sitio ?? props.parentEsEnSitio
}

function contextJuezIds(): number[] {
  const node = navStack.value.at(-1)
  if (node) return [...node.juez_ids]
  return [...(props.parentJuezIds ?? [])]
}

function contextSupervisorIds(): number[] {
  const node = navStack.value.at(-1)
  if (node) return [...node.supervisor_ids]
  return [...(props.parentSupervisorIds ?? [])]
}

function datesDiffer(start: Date | null, end: Date | null): boolean {
  if (!start || !end) return false
  return start.getTime() !== end.getTime()
}

function contextManejaFechaFin(): boolean {
  const node = navStack.value.at(-1)
  const start = dateOnly(contextStartsAt())
  const end = dateOnly(contextEndsAt())
  if (node?.maneja_fecha_fin) return true
  return datesDiffer(start, end)
}

function applyParentDefaults(): void {
  const start = dateOnly(contextStartsAt())
  const end = dateOnly(contextEndsAt())
  form.starts_at = start
  form.ends_at = end
  form.tipo_evento_id = contextTipoEventoId.value
  form.visibilidad = contextVisibilidad.value
  form.juez_ids = contextJuezIds()
  form.supervisor_ids = contextSupervisorIds()
  opts.esEnSitio = contextEsEnSitio()
  opts.manejaFechaFin = contextManejaFechaFin()
}

function isoToApiDate(iso: string): string {
  const d = dateOnly(iso)
  if (!d) return toApiDate(null)
  return toApiDate(d)
}

function resolveStartDate(): Date {
  if (selectedCategoria.value?.maneja_fecha_inicio && form.starts_at) {
    return dateOnly(form.starts_at) || new Date()
  }
  if (form.starts_at) {
    return dateOnly(form.starts_at) || dateOnly(contextStartsAt()) || new Date()
  }
  return dateOnly(contextStartsAt()) || new Date()
}

/**
 * Fecha fin a persistir.
 * Si el usuario activó “fecha final”, se respeta form.ends_at (no se sustituye
 * por el fin del padre ni se corrige silenciosamente al inicio del padre).
 */
function resolveEndDateForSave(start: Date): Date {
  if (opts.manejaFechaFin && form.ends_at) {
    const chosen = dateOnly(form.ends_at)
    if (chosen) return chosen
  }
  const parentEnd = dateOnly(contextEndsAt())
  if (parentEnd && parentEnd.getTime() >= start.getTime()) {
    return parentEnd
  }
  return addOneDay(start)
}

function endDateForPayload(start: Date): Date {
  return resolveEndDateForSave(start)
}

function datesFitParent(start: Date, end: Date): boolean {
  const min = dateOnly(contextStartsAt())
  const max = dateOnly(contextEndsAt())
  if (!min || !max) return true
  return start.getTime() >= min.getTime() && end.getTime() <= max.getTime()
}

const parentMinDate = computed(() => dateOnly(contextStartsAt()) ?? undefined)
const parentMaxDate = computed(() => dateOnly(contextEndsAt()) ?? undefined)
const childMinDate = computed(
  () => dateOnly(form.starts_at) || dateOnly(contextStartsAt()) || undefined,
)
const childMaxDate = computed(
  () => dateOnly(form.ends_at) || dateOnly(contextEndsAt()) || undefined,
)

async function loadChildItems(parentId: number | null): Promise<void> {
  if (!parentId) {
    childItems.value = []
    return
  }
  loadingChildren.value = true
  try {
    const page = await eventsService.list({ page: 1, per_page: 100, evento_padre_id: parentId })
    childItems.value = page.items
  } catch {
    childItems.value = []
  } finally {
    loadingChildren.value = false
  }
}

const childForm = reactive({
  name: '',
  descripcion: '',
  reglas: '',
  tipo_evento_id: null as number | null,
  puntaje_maximo: null as number | null,
  puntaje_por_participar: false,
  starts_at: null as Date | null,
  ends_at: null as Date | null,
  requiere_puesto_entrega: false,
  requiere_tiempo_entrega: false,
  resultado_esperado: null as number | null,
  participantes_min: null as number | null,
  participantes_max: null as number | null,
  permite_inscribir_no_participantes: false,
  participantes_genero: 'cualquiera' as 'mixto' | 'M' | 'F' | 'cualquiera',
  participantes_min_m: null as number | null,
  participantes_max_m: null as number | null,
  participantes_min_f: null as number | null,
  participantes_max_f: null as number | null,
  es_conjunto: false,
  nivel_conjunto: null as 'club' | 'iglesia' | 'distrito' | 'asociacion' | null,
  puntos_penalizacion: null as number | null,
  reglas_penalizacion: '',
  precio: null as number | null,
  tipos_evidencia: [] as Array<'link' | 'pdf' | 'imagen' | 'audio' | 'video'>,
  estado: 'publicado' as string,
  juez_ids: [] as number[],
  supervisor_ids: [] as number[],
  icono: 'pi pi-calendar',
  color: '#1e3a5f',
  icono_tamano: ICON_SIZE_DEFAULT,
})

const childOpts = reactive({
  manejaPuntaje: false,
  puntajeDesdeHijos: false,
  configCalificacion: false,
  controlParticipantes: false,
  esConjunto: false,
  manejaFechaFin: false,
  manejaPenalizaciones: false,
  tieneValor: false,
  requiereEvidencia: false,
  esEnSitio: true,
  tieneSubeventos: false,
})

const childVisualKind = ref<'imagen' | 'icono'>('icono')
const childPendingImage = ref<File | null>(null)
const childPendingPreview = ref<string | null>(null)
const childFormImageUrl = ref<string | null>(null)
const childUploadingImage = ref(false)
const childMateriales = ref<EventoArchivoMaterial[]>([])
const childPendingMaterialFiles = ref<File[]>([])
const childPendingYoutube = ref<Array<{ url: string; titulo?: string }>>([])
const childSaving = ref(false)
const childError = ref('')
const childEditingId = ref<number | null>(null)

const childImagePreview = computed(() => childPendingPreview.value || childFormImageUrl.value)

const childCriteriosSum = computed(() =>
  childAssignedCriterioIds.value.reduce((sum, id) => sum + (Number(childCriterioPoints[id]) || 0), 0),
)

const childCriteriosSumOk = computed(() => {
  if (!childAssignedCriterioIds.value.length) return true
  if (childForm.puntaje_maximo == null) return false
  return Math.abs(childCriteriosSum.value - Number(childForm.puntaje_maximo)) < 0.01
})

function clearPendingChildImage(): void {
  childPendingImage.value = null
  revokeUrl(childPendingPreview.value)
  childPendingPreview.value = null
}

async function onPickChildImage(file: File): Promise<void> {
  if (!file) return
  childPendingImage.value = file
  revokeUrl(childPendingPreview.value)
  childPendingPreview.value = URL.createObjectURL(file)
}

function setChildVisualKind(kind: 'imagen' | 'icono'): void {
  childVisualKind.value = kind
  if (kind === 'icono') {
    clearPendingChildImage()
    if (!childForm.icono) childForm.icono = form.icono || selectedCategoria.value?.icono || 'pi pi-calendar'
    if (!childForm.color) childForm.color = form.color || selectedCategoria.value?.color || '#1e3a5f'
  }
}

async function flushChildMaterials(id: number): Promise<void> {
  const files = [...childPendingMaterialFiles.value]
  const links = [...childPendingYoutube.value]
  childPendingMaterialFiles.value = []
  childPendingYoutube.value = []
  for (const file of files) {
    const created = await eventsService.addArchivoFile(id, file, file.name)
    childMateriales.value = [...childMateriales.value, created]
  }
  for (const link of links) {
    const created = await eventsService.addArchivoYoutube(id, link.url, link.titulo)
    childMateriales.value = [...childMateriales.value, created]
  }
}

function resetChildOpts(): void {
  childOpts.manejaPuntaje = false
  childOpts.puntajeDesdeHijos = false
  childOpts.configCalificacion = false
  childOpts.controlParticipantes = false
  childOpts.esConjunto = false
  childOpts.manejaFechaFin = false
  childOpts.manejaPenalizaciones = false
  childOpts.tieneValor = false
  childOpts.requiereEvidencia = false
  childOpts.tieneSubeventos = false
}

function resetChildForm(): void {
  childForm.name = ''
  childForm.descripcion = ''
  childForm.reglas = form.reglas || ''
  childForm.tipo_evento_id = form.tipo_evento_id
  childForm.puntaje_maximo = 100
  childForm.puntaje_por_participar = false
  childForm.starts_at = dateOnly(form.starts_at) || dateOnly(contextStartsAt())
  childForm.ends_at = dateOnly(form.ends_at) || dateOnly(contextEndsAt())
  childForm.requiere_puesto_entrega = false
  childForm.requiere_tiempo_entrega = false
  childForm.resultado_esperado = null
  childForm.participantes_min = 1
  childForm.participantes_max = null
  childForm.permite_inscribir_no_participantes = false
  childForm.participantes_genero = 'cualquiera'
  childForm.participantes_min_m = null
  childForm.participantes_max_m = null
  childForm.participantes_min_f = null
  childForm.participantes_max_f = null
  childForm.es_conjunto = false
  childForm.nivel_conjunto = null
  childForm.puntos_penalizacion = 5
  childForm.reglas_penalizacion = ''
  childForm.precio = null
  childForm.tipos_evidencia = ['link', 'pdf', 'imagen', 'audio', 'video']
  childForm.estado = 'publicado'
  childForm.juez_ids = [...form.juez_ids]
  childForm.supervisor_ids = [...form.supervisor_ids]
  childForm.icono = form.icono || selectedCategoria.value?.icono || 'pi pi-calendar'
  childForm.color = form.color || selectedCategoria.value?.color || '#1e3a5f'
  childForm.icono_tamano = form.icono_tamano || ICON_SIZE_DEFAULT
  childAssignedCriterioIds.value = []
  for (const key of Object.keys(childCriterioPoints)) delete childCriterioPoints[Number(key)]
  resetChildOpts()
  childOpts.esEnSitio = opts.esEnSitio
  childOpts.manejaFechaFin = opts.manejaFechaFin
  childVisualKind.value = 'icono'
  childFormImageUrl.value = null
  childMateriales.value = []
  childPendingMaterialFiles.value = []
  childPendingYoutube.value = []
  childError.value = ''
  childEditingId.value = null
  clearPendingChildImage()
}

function fillChildFromEvent(item: ClubEvent): void {
  childForm.name = item.name
  childForm.descripcion = item.descripcion ?? ''
  childForm.reglas = item.reglas ?? ''
  childForm.tipo_evento_id = item.tipo_evento_id ?? form.tipo_evento_id
  childForm.puntaje_maximo = item.puntaje_maximo ?? null
  childForm.puntaje_por_participar = !!item.puntaje_por_participar
  childForm.starts_at = dateOnly(item.starts_at) || dateOnly(form.starts_at)
  childForm.ends_at = dateOnly(item.ends_at) || dateOnly(form.ends_at)
  childForm.requiere_puesto_entrega = !!item.requiere_puesto_entrega
  childForm.requiere_tiempo_entrega = !!item.requiere_tiempo_entrega || item.tiempo_estimado_minutos != null
  childForm.resultado_esperado = item.resultado_esperado ?? null
  childForm.participantes_min = item.participantes_min ?? null
  childForm.participantes_max = item.participantes_max ?? null
  childForm.permite_inscribir_no_participantes = !!item.permite_inscribir_no_participantes
  childForm.participantes_genero =
    item.participantes_genero === 'M' ||
    item.participantes_genero === 'F' ||
    item.participantes_genero === 'mixto' ||
    item.participantes_genero === 'cualquiera'
      ? item.participantes_genero
      : 'cualquiera'
  childForm.participantes_min_m = item.participantes_min_m ?? null
  childForm.participantes_max_m = item.participantes_max_m ?? null
  childForm.participantes_min_f = item.participantes_min_f ?? null
  childForm.participantes_max_f = item.participantes_max_f ?? null
  childForm.es_conjunto = !!item.es_conjunto
  childForm.nivel_conjunto =
    (item.nivel_conjunto as 'club' | 'iglesia' | 'distrito' | 'asociacion' | null) ?? null
  childForm.puntos_penalizacion = item.puntos_penalizacion ?? null
  childForm.reglas_penalizacion = item.reglas_penalizacion || ''
  childForm.precio = item.precio ?? null
  childForm.tipos_evidencia = (item.tipos_evidencia || []).filter(
    (tipo): tipo is 'link' | 'pdf' | 'imagen' | 'audio' | 'video' =>
      tipo === 'link' ||
      tipo === 'pdf' ||
      tipo === 'imagen' ||
      tipo === 'audio' ||
      tipo === 'video',
  )
  childForm.estado = item.estado || 'publicado'
  childForm.juez_ids = [...(item.juez_ids ?? item.jueces?.map((j) => j.id) ?? [])]
  childForm.supervisor_ids = [...(item.supervisor_ids ?? item.supervisores?.map((s) => s.id) ?? [])]
  childForm.icono = item.icono || item.categoria_subevento?.icono || form.icono || 'pi pi-calendar'
  childForm.color = item.color || item.categoria_subevento?.color || form.color || '#1e3a5f'
  childForm.icono_tamano = clampIconSize(item.icono_tamano ?? form.icono_tamano)
  childAssignedCriterioIds.value = (item.criterios || []).map((c) => c.id)
  for (const key of Object.keys(childCriterioPoints)) delete childCriterioPoints[Number(key)]
  for (const criterio of item.criterios || []) {
    childCriterioPoints[criterio.id] = criterio.puntos
  }
  childOpts.puntajeDesdeHijos = false
  childOpts.tieneSubeventos = false
  childOpts.manejaPuntaje = Boolean(item.es_calificable) || item.puntaje_maximo != null
  childOpts.configCalificacion =
    !!item.requiere_puesto_entrega ||
    !!item.requiere_tiempo_entrega ||
    item.resultado_esperado != null ||
    item.tiempo_estimado_minutos != null
  childOpts.controlParticipantes =
    item.participantes_min != null ||
    item.participantes_max != null ||
    item.participantes_genero != null ||
    item.participantes_min_m != null ||
    item.participantes_max_m != null ||
    item.participantes_min_f != null ||
    item.participantes_max_f != null
  childOpts.esConjunto = !!item.es_conjunto
  childOpts.manejaFechaFin = !!item.maneja_fecha_fin
  childOpts.manejaPenalizaciones = !!item.maneja_penalizaciones
  childOpts.tieneValor = !!item.requiere_pago || item.precio != null
  childOpts.requiereEvidencia = !!item.requiere_evidencia
  childOpts.esEnSitio = item.es_en_sitio ?? true
  childVisualKind.value = item.icono || !item.image_url ? 'icono' : 'imagen'
  childFormImageUrl.value = item.image_url || null
  childMateriales.value = item.archivos ?? []
  childPendingMaterialFiles.value = []
  childPendingYoutube.value = []
  childError.value = ''
  clearPendingChildImage()
}

function openCreateNested(): void {
  opts.tieneSubeventos = true
  resetChildForm()
  childDrawerVisible.value = true
}

async function openEditNested(item: ClubEvent): Promise<void> {
  opts.tieneSubeventos = true
  childEditingId.value = item.id
  fillChildFromEvent(item)
  childDrawerVisible.value = true
  try {
    const full = await eventsService.get(item.id)
    if (childEditingId.value === item.id) fillChildFromEvent(full)
  } catch {
    if (!item.archivos?.length) {
      try {
        childMateriales.value = await eventsService.listArchivos(item.id)
      } catch {
        childMateriales.value = []
      }
    }
  }
}

function resolveChildEndDate(start: Date): Date {
  if (childOpts.manejaFechaFin && childForm.ends_at) {
    const chosen = dateOnly(childForm.ends_at)
    if (chosen) return chosen
  }
  const parentEnd = dateOnly(form.ends_at) || dateOnly(contextEndsAt())
  if (parentEnd && parentEnd.getTime() >= start.getTime()) return parentEnd
  return addOneDay(start)
}

async function saveChildSubevent(): Promise<void> {
  if (!childForm.name.trim()) {
    childError.value = t('events.wizard.subNameRequired')
    return
  }
  if (childOpts.esConjunto && !childForm.nivel_conjunto) {
    childError.value = t('events.wizard.subJointLevelRequired')
    return
  }
  if (childOpts.requiereEvidencia && !childForm.tipos_evidencia.length) {
    childError.value = t('events.wizard.subEvidenceTypesRequired')
    return
  }
  if (childOpts.manejaPuntaje && childAssignedCriterioIds.value.length && !childCriteriosSumOk.value) {
    childError.value = t('events.wizard.criteriaSumMismatch')
    return
  }
  if (childOpts.controlParticipantes && childForm.permite_inscribir_no_participantes) {
    const mixto = childForm.participantes_genero === 'mixto'
    const minOverMax =
      !mixto &&
      childForm.participantes_min != null &&
      childForm.participantes_max != null &&
      childForm.participantes_min > childForm.participantes_max
    const minOverMaxM =
      mixto &&
      childForm.participantes_min_m != null &&
      childForm.participantes_max_m != null &&
      childForm.participantes_min_m > childForm.participantes_max_m
    const minOverMaxF =
      mixto &&
      childForm.participantes_min_f != null &&
      childForm.participantes_max_f != null &&
      childForm.participantes_min_f > childForm.participantes_max_f
    if (minOverMax || minOverMaxM || minOverMaxF) {
      childError.value = t('events.wizard.subParticipantsRangeInvalid')
      return
    }
  }
  childSaving.value = true
  childError.value = ''
  try {
    opts.tieneSubeventos = true
    const parentId = editingId.value || (await saveSubevent(true))
    if (!parentId) {
      childError.value = errorMessage.value || t('events.wizard.subNameRequired')
      return
    }
    const start = dateOnly(childForm.starts_at) || dateOnly(form.starts_at) || new Date()
    const end = resolveChildEndDate(start)
    const parentStart = dateOnly(form.starts_at) || dateOnly(contextStartsAt())
    const parentEnd = dateOnly(form.ends_at) || dateOnly(contextEndsAt())
    if (
      childOpts.esEnSitio &&
      parentStart &&
      parentEnd &&
      (start.getTime() < parentStart.getTime() || end.getTime() > parentEnd.getTime())
    ) {
      childError.value = t('events.subDatesOutOfRange')
      return
    }
    const childPayload = {
      name: childForm.name.trim(),
      descripcion: childForm.descripcion.trim() || null,
      reglas: normalizeRichText(childForm.reglas),
      evento_padre_id: parentId,
      organizacion_id: contextOrganizacionId(),
      categoria_subevento_id: form.categoria_subevento_id,
      tipo_evento_id: childForm.tipo_evento_id ?? form.tipo_evento_id,
      puntaje_maximo: childOpts.manejaPuntaje ? childForm.puntaje_maximo : null,
      puntaje_desde_hijos: false,
      puntaje_por_participar: childOpts.manejaPuntaje && childForm.puntaje_por_participar,
      tiempo_estimado_minutos: null,
      requiere_puesto_entrega: childOpts.configCalificacion && childForm.requiere_puesto_entrega,
      requiere_tiempo_entrega: childOpts.configCalificacion && childForm.requiere_tiempo_entrega,
      resultado_esperado:
        childOpts.configCalificacion &&
        childForm.resultado_esperado != null &&
        childForm.resultado_esperado > 0
          ? childForm.resultado_esperado
          : null,
      participantes_min:
        childOpts.controlParticipantes &&
        childForm.permite_inscribir_no_participantes &&
        childForm.participantes_genero !== 'mixto'
          ? childForm.participantes_min
          : childOpts.controlParticipantes &&
              childForm.permite_inscribir_no_participantes &&
              childForm.participantes_genero === 'mixto'
            ? (childForm.participantes_min_m ?? 0) + (childForm.participantes_min_f ?? 0) || null
            : null,
      participantes_max:
        childOpts.controlParticipantes &&
        childForm.permite_inscribir_no_participantes &&
        childForm.participantes_genero !== 'mixto'
          ? childForm.participantes_max
          : childOpts.controlParticipantes &&
              childForm.permite_inscribir_no_participantes &&
              childForm.participantes_genero === 'mixto' &&
              childForm.participantes_max_m != null &&
              childForm.participantes_max_f != null
            ? childForm.participantes_max_m + childForm.participantes_max_f
            : null,
      permite_inscribir_no_participantes: childOpts.controlParticipantes
        ? childForm.permite_inscribir_no_participantes
        : false,
      participantes_genero:
        childOpts.controlParticipantes && childForm.permite_inscribir_no_participantes
          ? childForm.participantes_genero
          : null,
      participantes_min_m:
        childOpts.controlParticipantes &&
        childForm.permite_inscribir_no_participantes &&
        childForm.participantes_genero === 'mixto'
          ? childForm.participantes_min_m
          : null,
      participantes_max_m:
        childOpts.controlParticipantes &&
        childForm.permite_inscribir_no_participantes &&
        childForm.participantes_genero === 'mixto'
          ? childForm.participantes_max_m
          : null,
      participantes_min_f:
        childOpts.controlParticipantes &&
        childForm.permite_inscribir_no_participantes &&
        childForm.participantes_genero === 'mixto'
          ? childForm.participantes_min_f
          : null,
      participantes_max_f:
        childOpts.controlParticipantes &&
        childForm.permite_inscribir_no_participantes &&
        childForm.participantes_genero === 'mixto'
          ? childForm.participantes_max_f
          : null,
      equipos_org_min: null,
      equipos_org_max: null,
      es_conjunto: childOpts.esConjunto,
      nivel_conjunto: childOpts.esConjunto ? childForm.nivel_conjunto : null,
      maneja_fecha_fin: childOpts.manejaFechaFin || datesDiffer(start, end),
      maneja_penalizaciones: childOpts.manejaPenalizaciones,
      puntos_penalizacion: childOpts.manejaPenalizaciones ? childForm.puntos_penalizacion : null,
      reglas_penalizacion: childOpts.manejaPenalizaciones
        ? childForm.reglas_penalizacion.trim() || null
        : null,
      requiere_pago: childOpts.tieneValor,
      precio: childOpts.tieneValor ? childForm.precio : null,
      requiere_evidencia: childOpts.requiereEvidencia,
      tipos_evidencia: childOpts.requiereEvidencia ? [...childForm.tipos_evidencia] : null,
      juez_ids: [...childForm.juez_ids],
      supervisor_ids: [...childForm.supervisor_ids],
      criterios:
        childOpts.manejaPuntaje && !childForm.puntaje_por_participar
          ? childAssignedCriterioIds.value.map((id, index) => ({
              id,
              puntos: Number(childCriterioPoints[id] || 0),
              orden: index,
            }))
          : [],
      estado: childForm.estado,
      visibilidad: contextVisibilidad.value,
      is_active: childForm.estado === 'publicado',
      es_calificable: childOpts.manejaPuntaje,
      es_en_sitio: childOpts.esEnSitio,
      tiene_subeventos: false,
      starts_at: toApiDate(start),
      ends_at: toApiDate(end),
      cupo_ilimitado: true,
      icono: childVisualKind.value === 'icono' ? childForm.icono || 'pi pi-calendar' : null,
      color: childVisualKind.value === 'icono' ? childForm.color || null : null,
      icono_tamano: childVisualKind.value === 'icono' ? clampIconSize(childForm.icono_tamano) : null,
      ...(childVisualKind.value === 'icono' ? { image_url: null } : {}),
    }
    let savedId = childEditingId.value
    let savedChild: ClubEvent | null = null
    if (childEditingId.value) {
      savedChild = await eventsService.update(childEditingId.value, childPayload)
    } else {
      savedChild = await eventsService.create(childPayload)
      savedId = savedChild.id
      childEditingId.value = savedChild.id
    }
    if (childVisualKind.value === 'imagen' && childPendingImage.value && savedId) {
      childUploadingImage.value = true
      try {
        const updated = await eventsService.uploadImage(savedId, childPendingImage.value)
        childFormImageUrl.value = updated.image_url
        clearPendingChildImage()
      } finally {
        childUploadingImage.value = false
      }
    }
    if (savedId) await flushChildMaterials(savedId)
    await offerJuezConflicts(savedChild)
    childDrawerVisible.value = false
    await loadChildItems(parentId)
    await load()
    emit('changed')
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.subCreateSuccess'),
      life: 2000,
    })
  } catch (error) {
    childError.value = getApiErrorMessage(error)
  } finally {
    childSaving.value = false
  }
}

async function flushSubeventMaterials(id: number): Promise<void> {
  const files = [...pendingMaterialFiles.value]
  const links = [...pendingYoutube.value]
  pendingMaterialFiles.value = []
  pendingYoutube.value = []
  for (const file of files) {
    const created = await eventsService.addArchivoFile(id, file, file.name)
    materiales.value = [...materiales.value, created]
  }
  for (const link of links) {
    const created = await eventsService.addArchivoYoutube(id, link.url, link.titulo)
    materiales.value = [...materiales.value, created]
  }
}

function resetForm(): void {
  form.name = ''
  form.descripcion = ''
  form.reglas = ''
  form.categoria_subevento_id = categoriasDisponibles.value[0]?.id ?? null
  form.puntaje_maximo = 100
  form.puntaje_por_participar = false
  form.tiempo_estimado_minutos = null
  form.requiere_puesto_entrega = false
  form.requiere_tiempo_entrega = false
  form.resultado_esperado = null
  form.participantes_min = 1
  form.participantes_max = null
  form.permite_inscribir_no_participantes = false
  form.participantes_genero = 'cualquiera'
  form.participantes_min_m = null
  form.participantes_max_m = null
  form.participantes_min_f = null
  form.participantes_max_f = null
  form.es_conjunto = false
  form.nivel_conjunto = null
  form.puntos_penalizacion = 5
  form.reglas_penalizacion = ''
  form.precio = null
  form.tipos_evidencia = ['link', 'pdf', 'imagen', 'audio', 'video']
  form.estado = 'publicado'
  assignedCriterioIds.value = []
  for (const key of Object.keys(criterioPoints)) delete criterioPoints[Number(key)]
  opts.manejaPuntaje = false
  opts.puntajeDesdeHijos = false
  opts.configCalificacion = false
  opts.controlParticipantes = false
  opts.esConjunto = false
  opts.manejaFechaFin = false
  opts.manejaPenalizaciones = false
  opts.tieneValor = false
  opts.requiereEvidencia = false
  opts.tieneSubeventos = false
  applyParentDefaults()
  visualKind.value = 'icono'
  form.icono = selectedCategoria.value?.icono || 'pi pi-calendar'
  form.color = selectedCategoria.value?.color || '#1e3a5f'
  form.icono_tamano = ICON_SIZE_DEFAULT
  childrenScoreSum.value = 0
  formImageUrl.value = null
  materiales.value = []
  pendingMaterialFiles.value = []
  pendingYoutube.value = []
  childItems.value = []
  clearPendingImage()
}

function openCreate(): void {
  editingId.value = null
  editingParentId.value = activeParentId.value
  resetForm()
  drawerVisible.value = true
}

function openCreateChild(item: ClubEvent): void {
  enterChildren(item)
  openCreate()
}

function openEdit(item: ClubEvent): void {
  editingId.value = item.id
  editingParentId.value = item.evento_padre_id ?? activeParentId.value
  form.name = item.name
  form.descripcion = item.descripcion || ''
  form.reglas = item.reglas || ''
  form.categoria_subevento_id = item.categoria_subevento_id ?? null
  form.tipo_evento_id = item.tipo_evento_id ?? null
  form.puntaje_maximo = item.puntaje_maximo ?? null
  form.puntaje_por_participar = !!item.puntaje_por_participar
  form.starts_at = dateOnly(item.starts_at)
  form.ends_at = dateOnly(item.ends_at)
  form.tiempo_estimado_minutos = item.tiempo_estimado_minutos ?? null
  form.requiere_puesto_entrega = !!item.requiere_puesto_entrega
  form.requiere_tiempo_entrega = !!item.requiere_tiempo_entrega || item.tiempo_estimado_minutos != null
  form.resultado_esperado = item.resultado_esperado ?? null
  form.participantes_min = item.participantes_min ?? null
  form.participantes_max = item.participantes_max ?? null
  form.permite_inscribir_no_participantes = !!item.permite_inscribir_no_participantes
  form.participantes_genero =
    item.participantes_genero === 'M' ||
    item.participantes_genero === 'F' ||
    item.participantes_genero === 'mixto' ||
    item.participantes_genero === 'cualquiera'
      ? item.participantes_genero
      : 'cualquiera'
  form.participantes_min_m = item.participantes_min_m ?? null
  form.participantes_max_m = item.participantes_max_m ?? null
  form.participantes_min_f = item.participantes_min_f ?? null
  form.participantes_max_f = item.participantes_max_f ?? null
  form.es_conjunto = !!item.es_conjunto
  form.nivel_conjunto =
    (item.nivel_conjunto as 'club' | 'iglesia' | 'distrito' | 'asociacion' | null) ?? null
  form.puntos_penalizacion = item.puntos_penalizacion ?? null
  form.reglas_penalizacion = item.reglas_penalizacion || ''
  form.precio = item.precio ?? null
  form.tipos_evidencia = (item.tipos_evidencia || []).filter(
    (tipo): tipo is 'link' | 'pdf' | 'imagen' | 'audio' | 'video' =>
      tipo === 'link' ||
      tipo === 'pdf' ||
      tipo === 'imagen' ||
      tipo === 'audio' ||
      tipo === 'video',
  )
  form.estado = item.estado || 'borrador'
  form.visibilidad = item.visibilidad ?? 'organizacion'
  form.juez_ids = [...(item.juez_ids ?? item.jueces?.map((j) => j.id) ?? [])]
  form.supervisor_ids = [
    ...(item.supervisor_ids ?? item.supervisores?.map((s) => s.id) ?? []),
  ]
  assignedCriterioIds.value = (item.criterios || []).map((c) => c.id)
  for (const key of Object.keys(criterioPoints)) delete criterioPoints[Number(key)]
  for (const c of item.criterios || []) {
    criterioPoints[c.id] = c.puntos
  }
  opts.puntajeDesdeHijos = !!item.puntaje_desde_hijos
  opts.manejaPuntaje =
    !opts.puntajeDesdeHijos &&
    (Boolean(item.es_calificable) || item.puntaje_maximo != null)
  opts.configCalificacion =
    !!item.requiere_puesto_entrega ||
    !!item.requiere_tiempo_entrega ||
    item.resultado_esperado != null ||
    item.tiempo_estimado_minutos != null
  opts.controlParticipantes =
    item.participantes_min != null ||
    item.participantes_max != null ||
    item.participantes_genero != null ||
    item.participantes_min_m != null ||
    item.participantes_max_m != null ||
    item.participantes_min_f != null ||
    item.participantes_max_f != null
  opts.esConjunto = !!item.es_conjunto
  opts.manejaFechaFin = !!item.maneja_fecha_fin
  opts.manejaPenalizaciones = !!item.maneja_penalizaciones
  opts.tieneValor = !!item.requiere_pago || item.precio != null
  opts.requiereEvidencia = !!item.requiere_evidencia
  opts.esEnSitio = item.es_en_sitio ?? true
  opts.tieneSubeventos = !!item.tiene_subeventos
  form.icono = item.icono || item.categoria_subevento?.icono || 'pi pi-calendar'
  form.color = item.color || item.categoria_subevento?.color || '#1e3a5f'
  form.icono_tamano = clampIconSize(item.icono_tamano)
  visualKind.value = item.icono || !item.image_url ? 'icono' : 'imagen'
  formImageUrl.value = item.image_url || null
  materiales.value = item.archivos ?? []
  pendingMaterialFiles.value = []
  pendingYoutube.value = []
  clearPendingImage()
  drawerVisible.value = true
  selectedId.value = item.id
  void refreshChildrenScoreSum(item.id)
  void loadChildItems(item.id)
}

async function refreshChildrenScoreSum(parentId: number | null): Promise<void> {
  if (!parentId) {
    childrenScoreSum.value = 0
    return
  }
  loadingChildrenScore.value = true
  try {
    const page = await eventsService.list({
      page: 1,
      per_page: 100,
      evento_padre_id: parentId,
    })
    childrenScoreSum.value = page.items.reduce((sum, i) => sum + Number(i.puntaje_maximo || 0), 0)
    if (opts.puntajeDesdeHijos) {
      form.puntaje_maximo = childrenScoreSum.value
    }
  } catch {
    childrenScoreSum.value = 0
  } finally {
    loadingChildrenScore.value = false
  }
}

function nivelConjuntoLabel(nivel: string | null | undefined): string {
  if (!nivel) return '—'
  return nivelConjuntoOptions.value.find((o) => o.value === nivel)?.label || nivel
}


function enterChildren(item: ClubEvent): void {
  navStack.value = [...navStack.value, toNavNode(item)]
  selectedId.value = null
  search.value = ''
  detailTab.value = 'info'
  void load()
}

function goBreadcrumb(index: number): void {
  if (index < 0) {
    navStack.value = []
  } else {
    navStack.value = navStack.value.slice(0, index + 1)
  }
  selectedId.value = null
  search.value = ''
  void load()
}

async function load(): Promise<void> {
  if (!activeParentId.value) {
    items.value = []
    return
  }
  loading.value = true
  errorMessage.value = ''
  try {
    const page = await eventsService.list({
      page: 1,
      per_page: 100,
      evento_padre_id: activeParentId.value,
    })
    items.value = page.items
    if (selectedId.value && !findInTree(items.value, selectedId.value)) {
      selectedId.value = null
    }
    if (!selectedId.value && items.value.length) {
      selectedId.value = items.value[0].id
    }
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
    items.value = []
  } finally {
    loading.value = false
  }
}

async function saveSubevent(keepDrawerOpen = false): Promise<number | null> {
  if (!activeParentId.value) return null
  if (!form.name.trim()) {
    errorMessage.value = t('events.wizard.subNameRequired')
    return null
  }
  if (opts.esConjunto && !form.nivel_conjunto) {
    errorMessage.value = t('events.wizard.subJointLevelRequired')
    return null
  }
  if (opts.requiereEvidencia && !form.tipos_evidencia.length) {
    errorMessage.value = t('events.wizard.subEvidenceTypesRequired')
    return null
  }
  if (opts.manejaPuntaje && !opts.puntajeDesdeHijos && assignedCriterioIds.value.length && !criteriosSumOk.value) {
    errorMessage.value = t('events.wizard.criteriaSumMismatch')
    return null
  }
  if (opts.controlParticipantes && form.permite_inscribir_no_participantes) {
    const mixto = form.participantes_genero === 'mixto'
    const minOverMax =
      !mixto &&
      form.participantes_min != null &&
      form.participantes_max != null &&
      form.participantes_min > form.participantes_max
    const minOverMaxM =
      mixto &&
      form.participantes_min_m != null &&
      form.participantes_max_m != null &&
      form.participantes_min_m > form.participantes_max_m
    const minOverMaxF =
      mixto &&
      form.participantes_min_f != null &&
      form.participantes_max_f != null &&
      form.participantes_min_f > form.participantes_max_f
    if (minOverMax || minOverMaxM || minOverMaxF) {
      errorMessage.value = t('events.wizard.subParticipantsRangeInvalid')
      return null
    }
  }
  saving.value = true
  errorMessage.value = ''
  try {
    const controlsOwnStart = Boolean(selectedCategoria.value?.maneja_fecha_inicio && form.starts_at)
    let startDate = resolveStartDate()
    let endDate = endDateForPayload(startDate)

    // Si solo se gestiona fecha fin y queda antes del inicio heredado del padre,
    // alinear starts_at para no fallar after_or_equal ni pisar la fecha elegida.
    if (opts.manejaFechaFin && form.ends_at) {
      const chosenEnd = dateOnly(form.ends_at)
      if (chosenEnd) {
        endDate = chosenEnd
        if (!controlsOwnStart && startDate.getTime() > endDate.getTime()) {
          startDate = endDate
        } else if (controlsOwnStart) {
          endDate = ensureEndAfterStart(startDate, endDate)
        }
      }
    } else {
      endDate = ensureEndAfterStart(startDate, endDate)
    }

    if (opts.esEnSitio && !datesFitParent(startDate, endDate)) {
      errorMessage.value = t('events.subDatesOutOfRange')
      return null
    }

    const payload = {
      name: form.name.trim(),
      descripcion: form.descripcion.trim() || null,
      reglas: normalizeRichText(form.reglas),
      // Solo al crear se fija el padre; al editar se conserva el padre real
      // (evita reparentar hijos anidados abiertos desde el acordeón).
      ...(editingId.value
        ? {}
        : { evento_padre_id: editingParentId.value ?? activeParentId.value }),
      organizacion_id: contextOrganizacionId(),
      categoria_subevento_id: form.categoria_subevento_id,
      tipo_evento_id: form.tipo_evento_id,
      puntaje_maximo: opts.puntajeDesdeHijos
        ? childrenScoreSum.value
        : opts.manejaPuntaje
          ? form.puntaje_maximo
          : null,
      puntaje_desde_hijos: opts.puntajeDesdeHijos,
      puntaje_por_participar:
        opts.manejaPuntaje && !opts.puntajeDesdeHijos && form.puntaje_por_participar,
      tiempo_estimado_minutos: null,
      requiere_puesto_entrega: opts.configCalificacion && form.requiere_puesto_entrega,
      requiere_tiempo_entrega: opts.configCalificacion && form.requiere_tiempo_entrega,
      resultado_esperado:
        opts.configCalificacion && form.resultado_esperado != null && form.resultado_esperado > 0
          ? form.resultado_esperado
          : null,
      participantes_min:
        opts.controlParticipantes &&
        form.permite_inscribir_no_participantes &&
        form.participantes_genero !== 'mixto'
          ? form.participantes_min
          : opts.controlParticipantes &&
              form.permite_inscribir_no_participantes &&
              form.participantes_genero === 'mixto'
            ? (form.participantes_min_m ?? 0) + (form.participantes_min_f ?? 0) || null
            : null,
      participantes_max:
        opts.controlParticipantes &&
        form.permite_inscribir_no_participantes &&
        form.participantes_genero !== 'mixto'
          ? form.participantes_max
          : opts.controlParticipantes &&
              form.permite_inscribir_no_participantes &&
              form.participantes_genero === 'mixto' &&
              form.participantes_max_m != null &&
              form.participantes_max_f != null
            ? form.participantes_max_m + form.participantes_max_f
            : null,
      permite_inscribir_no_participantes: opts.controlParticipantes
        ? form.permite_inscribir_no_participantes
        : false,
      participantes_genero:
        opts.controlParticipantes && form.permite_inscribir_no_participantes
          ? form.participantes_genero
          : null,
      participantes_min_m:
        opts.controlParticipantes &&
        form.permite_inscribir_no_participantes &&
        form.participantes_genero === 'mixto'
          ? form.participantes_min_m
          : null,
      participantes_max_m:
        opts.controlParticipantes &&
        form.permite_inscribir_no_participantes &&
        form.participantes_genero === 'mixto'
          ? form.participantes_max_m
          : null,
      participantes_min_f:
        opts.controlParticipantes &&
        form.permite_inscribir_no_participantes &&
        form.participantes_genero === 'mixto'
          ? form.participantes_min_f
          : null,
      participantes_max_f:
        opts.controlParticipantes &&
        form.permite_inscribir_no_participantes &&
        form.participantes_genero === 'mixto'
          ? form.participantes_max_f
          : null,
      equipos_org_min: null,
      equipos_org_max: null,
      es_conjunto: opts.esConjunto,
      nivel_conjunto: opts.esConjunto ? form.nivel_conjunto : null,
      maneja_fecha_fin: opts.manejaFechaFin,
      maneja_penalizaciones: opts.manejaPenalizaciones,
      puntos_penalizacion: opts.manejaPenalizaciones ? form.puntos_penalizacion : null,
      reglas_penalizacion: opts.manejaPenalizaciones
        ? form.reglas_penalizacion.trim() || null
        : null,
      requiere_pago: opts.tieneValor,
      precio: opts.tieneValor ? form.precio : null,
      requiere_evidencia: opts.requiereEvidencia,
      tipos_evidencia: opts.requiereEvidencia ? [...form.tipos_evidencia] : null,
      juez_ids: [...form.juez_ids],
      supervisor_ids: [...form.supervisor_ids],
      criterios:
        opts.manejaPuntaje && !opts.puntajeDesdeHijos && !form.puntaje_por_participar
          ? assignedCriterioIds.value.map((id, index) => ({
              id,
              puntos: Number(criterioPoints[id] || 0),
              orden: index,
            }))
          : [],
      estado: form.estado,
      visibilidad: contextVisibilidad.value,
      is_active: form.estado === 'publicado',
      es_calificable: opts.manejaPuntaje || opts.puntajeDesdeHijos,
      es_en_sitio: opts.esEnSitio,
      tiene_subeventos: opts.tieneSubeventos,
      starts_at: toApiDate(startDate),
      ends_at: toApiDate(endDate),
      cupo_ilimitado: true,
      icono: visualKind.value === 'icono' ? form.icono || 'pi pi-calendar' : null,
      color: visualKind.value === 'icono' ? form.color || null : null,
      icono_tamano: visualKind.value === 'icono' ? clampIconSize(form.icono_tamano) : null,
      ...(visualKind.value === 'icono' ? { image_url: null } : {}),
    }

    let savedId = editingId.value
    let savedEvent: ClubEvent | null = null
    if (editingId.value) {
      savedEvent = await eventsService.update(editingId.value, payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('events.wizard.subUpdateSuccess'),
        life: 2000,
      })
    } else {
      savedEvent = await eventsService.create(payload)
      savedId = savedEvent.id
      selectedId.value = savedEvent.id
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('events.wizard.subCreateSuccess'),
        life: 2000,
      })
    }

    if (visualKind.value === 'imagen' && pendingImage.value && savedId) {
      uploadingImage.value = true
      try {
        const updated = await eventsService.uploadImage(savedId, pendingImage.value)
        formImageUrl.value = updated.image_url
        clearPendingImage()
      } finally {
        uploadingImage.value = false
      }
    }
    if (savedId) await flushSubeventMaterials(savedId)
    if (savedId) editingId.value = savedId
    if (savedId && opts.tieneSubeventos) await loadChildItems(savedId)
    await offerJuezConflicts(savedEvent)

    if (!keepDrawerOpen) drawerVisible.value = false
    await load()
    emit('changed')
    return savedId
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
    return null
  } finally {
    saving.value = false
  }
}

async function removeSubevent(item: ClubEvent): Promise<void> {
  if (hasChildren(item)) return
  if (!confirm(t('events.wizard.subDeleteConfirm'))) return
  try {
    await eventsService.remove(item.id)
    if (selectedId.value === item.id) selectedId.value = null
    await load()
    emit('changed')
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.subDeleteSuccess'),
      life: 2000,
    })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  }
}

const duplicatingId = ref<number | null>(null)

async function duplicateSubevent(item: ClubEvent): Promise<void> {
  if (duplicatingId.value) return
  duplicatingId.value = item.id
  try {
    const cloned = await eventsService.duplicate(item.id)
    await load()
    emit('changed')
    selectedId.value = cloned.id
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.subDuplicateSuccess'),
      life: 2500,
    })
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    duplicatingId.value = null
  }
}

async function patchSelectedEstado(estado: string): Promise<void> {
  if (!selected.value) return
  try {
    await eventsService.update(selected.value.id, {
      name: selected.value.name,
      starts_at: isoToApiDate(selected.value.starts_at),
      ends_at: isoToApiDate(selected.value.ends_at),
      estado,
      is_active: estado === 'publicado',
    })
    await load()
  } catch (error) {
    errorMessage.value = getApiErrorMessage(error)
  }
}

function siblingsOf(parentId: number): ClubEvent[] {
  if (parentId === activeParentId.value) return items.value
  return findInTree(items.value, parentId)?.hijos ?? []
}

function isDescendantOf(ancestorId: number, candidateId: number): boolean {
  const ancestor = findInTree(items.value, ancestorId)
  if (!ancestor?.hijos?.length) return false
  return !!findInTree(ancestor.hijos, candidateId)
}

function resolveDropMode(
  e: DragEvent,
  el: HTMLElement,
  current?: 'before' | 'after' | 'into' | null,
): 'before' | 'after' | 'into' {
  const rect = el.getBoundingClientRect()
  const ratio = (e.clientY - rect.top) / Math.max(rect.height, 1)
  // Histéresis: evita saltos al pasar cerca de los umbrales
  if (current === 'before') {
    if (ratio < 0.32) return 'before'
    if (ratio > 0.78) return 'after'
    return 'into'
  }
  if (current === 'after') {
    if (ratio > 0.68) return 'after'
    if (ratio < 0.22) return 'before'
    return 'into'
  }
  if (current === 'into') {
    if (ratio < 0.18) return 'before'
    if (ratio > 0.82) return 'after'
    return 'into'
  }
  if (ratio < 0.22) return 'before'
  if (ratio > 0.78) return 'after'
  return 'into'
}

function clearDragGhost(): void {
  if (dragGhostEl) {
    dragGhostEl.remove()
    dragGhostEl = null
  }
}

function createDragGhost(item: ClubEvent, e: DragEvent): void {
  clearDragGhost()
  const ghost = document.createElement('div')
  ghost.textContent = item.name
  Object.assign(ghost.style, {
    position: 'fixed',
    top: '-1000px',
    left: '-1000px',
    zIndex: '9999',
    padding: '0.55rem 0.85rem',
    borderRadius: '10px',
    background: 'rgba(255,255,255,0.96)',
    border: '1px solid rgba(37,99,235,0.35)',
    boxShadow: '0 12px 28px rgba(15,23,42,0.18)',
    color: '#0f172a',
    fontSize: '0.86rem',
    fontWeight: '600',
    maxWidth: '240px',
    whiteSpace: 'nowrap',
    overflow: 'hidden',
    textOverflow: 'ellipsis',
    pointerEvents: 'none',
  })
  document.body.appendChild(ghost)
  dragGhostEl = ghost
  e.dataTransfer?.setDragImage(ghost, 20, 18)
}

function flashPulse(id: number): void {
  pulseId.value = id
  clearTimeout(pulseTimer)
  pulseTimer = setTimeout(() => {
    if (pulseId.value === id) pulseId.value = null
  }, 700)
}

function onDragStartItem(item: ClubEvent, e: DragEvent): void {
  dragId.value = item.id
  dragFrom.value = items.value.findIndex((i) => i.id === item.id)
  e.dataTransfer?.setData('text/plain', String(item.id))
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
  createDragGhost(item, e)
}

function onDragEndItem(): void {
  dragId.value = null
  dropTarget.value = null
  dragFrom.value = null
  clearDragGhost()
  if (dropRaf) {
    cancelAnimationFrame(dropRaf)
    dropRaf = 0
  }
}

function onDragOverItem(item: ClubEvent, e: DragEvent): void {
  if (dragId.value == null || dragId.value === item.id) return
  if (isDescendantOf(dragId.value, item.id)) return
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'move'
  const el = e.currentTarget as HTMLElement
  const clientY = e.clientY
  if (dropRaf) cancelAnimationFrame(dropRaf)
  dropRaf = requestAnimationFrame(() => {
    dropRaf = 0
    const current = dropTarget.value?.id === item.id ? dropTarget.value.mode : null
    const mode = resolveDropMode({ clientY } as DragEvent, el, current)
    if (dropTarget.value?.id === item.id && dropTarget.value.mode === mode) return
    dropTarget.value = { id: item.id, mode }
  })
}

async function onDropItem(item: ClubEvent, e: DragEvent): Promise<void> {
  e.preventDefault()
  e.stopPropagation()
  const movedId = dragId.value
  const mode =
    dropTarget.value?.id === item.id
      ? dropTarget.value.mode
      : resolveDropMode(e, e.currentTarget as HTMLElement)
  dragId.value = null
  dropTarget.value = null
  dragFrom.value = null
  clearDragGhost()

  if (movedId == null || movedId === item.id || !activeParentId.value || moving.value) return
  if (isDescendantOf(movedId, item.id)) return

  const moved = findInTree(items.value, movedId)
  if (!moved) return

  let newParentId: number
  let beforeId: number | null = null

  if (mode === 'into') {
    newParentId = item.id
    beforeId = null
    if (moved.evento_padre_id === item.id) return
  } else {
    newParentId = item.evento_padre_id ?? activeParentId.value
    if (mode === 'before') {
      beforeId = item.id
    } else {
      const siblings = siblingsOf(newParentId)
      const idx = siblings.findIndex((s) => s.id === item.id)
      beforeId = siblings[idx + 1]?.id ?? null
    }

    if ((moved.evento_padre_id ?? activeParentId.value) === newParentId) {
      const siblings = siblingsOf(newParentId)
      const fromIdx = siblings.findIndex((s) => s.id === movedId)
      const toIdx = siblings.findIndex((s) => s.id === item.id)
      if (fromIdx < 0 || toIdx < 0) return
      if (mode === 'before' && fromIdx === toIdx - 1) return
      if (mode === 'after' && fromIdx === toIdx + 1) return
    }
  }

  const snapshot = items.value.map(cloneNode)
  const nextTree = applyLocalMove(
    snapshot,
    movedId,
    newParentId,
    beforeId,
    activeParentId.value,
  )
  if (!nextTree) return

  items.value = nextTree
  if (mode === 'into') {
    const next = new Set(expandedChildren.value)
    next.add(item.id)
    expandedChildren.value = next
  }
  flashPulse(movedId)
  emit('changed')

  moving.value = true
  errorMessage.value = ''
  try {
    await eventsService.move(movedId, {
      evento_padre_id: newParentId,
      before_id: beforeId,
    })
  } catch (error) {
    items.value = snapshot
    errorMessage.value = getApiErrorMessage(error)
  } finally {
    moving.value = false
  }
}

function dropClassFor(itemId: number): string {
  if (dropTarget.value?.id !== itemId) return ''
  return `is-drop-${dropTarget.value.mode}`
}

function iconFor(item: ClubEvent): string {
  return item.icono || item.categoria_subevento?.icono || item.tipo_evento?.icono || 'pi pi-calendar'
}

function iconStyleFor(item: ClubEvent, max = 28): Record<string, string> {
  return iconBoxStyle(resolveEventIconColor(item), { size: item.icono_tamano, maxSize: max })
}

function showsImage(item: ClubEvent): boolean {
  return Boolean(item.image_url) && !item.icono
}

function setVisualKind(kind: 'imagen' | 'icono'): void {
  visualKind.value = kind
  if (kind === 'icono') {
    clearPendingImage()
    if (!form.icono) form.icono = selectedCategoria.value?.icono || 'pi pi-calendar'
    if (!form.color) form.color = selectedCategoria.value?.color || '#1e3a5f'
  }
}

watch(
  () => opts.manejaPuntaje,
  (on) => {
    if (on && form.puntaje_maximo == null && !opts.puntajeDesdeHijos) form.puntaje_maximo = 100
  },
)

watch(
  () => opts.puntajeDesdeHijos,
  (on) => {
    if (on) {
      form.puntaje_por_participar = false
      form.puntaje_maximo = childrenScoreSum.value
      if (editingId.value) void refreshChildrenScoreSum(editingId.value)
    }
  },
)

watch(
  () => opts.configCalificacion,
  (on) => {
    if (!on) {
      form.requiere_puesto_entrega = false
      form.requiere_tiempo_entrega = false
      form.resultado_esperado = null
    }
  },
)

function ensureParticipantQuotaDefaults(): void {
  if (!opts.controlParticipantes || !form.permite_inscribir_no_participantes) return
  if (!form.participantes_genero) form.participantes_genero = 'cualquiera'
  if (form.participantes_genero !== 'mixto' && form.participantes_min == null) {
    form.participantes_min = 1
  }
  if (form.participantes_genero === 'mixto') {
    if (form.participantes_min_m == null) form.participantes_min_m = 1
    if (form.participantes_min_f == null) form.participantes_min_f = 1
  }
}

watch(
  () => opts.controlParticipantes,
  (on) => {
    if (on) ensureParticipantQuotaDefaults()
  },
)

watch(
  () => form.permite_inscribir_no_participantes,
  (on) => {
    if (on) ensureParticipantQuotaDefaults()
  },
)

watch(
  () => form.participantes_genero,
  (genero) => {
    if (!opts.controlParticipantes || !form.permite_inscribir_no_participantes) return
    if (genero === 'mixto') {
      if (form.participantes_min_m == null) form.participantes_min_m = 1
      if (form.participantes_min_f == null) form.participantes_min_f = 1
    } else if (form.participantes_min == null) {
      form.participantes_min = 1
    }
  },
)

watch(
  () => childOpts.manejaPuntaje,
  (on) => {
    if (on && childForm.puntaje_maximo == null) childForm.puntaje_maximo = 100
  },
)

watch(
  () => childOpts.configCalificacion,
  (on) => {
    if (!on) {
      childForm.requiere_puesto_entrega = false
      childForm.requiere_tiempo_entrega = false
      childForm.resultado_esperado = null
    }
  },
)

function ensureChildParticipantQuotaDefaults(): void {
  if (!childOpts.controlParticipantes || !childForm.permite_inscribir_no_participantes) return
  if (!childForm.participantes_genero) childForm.participantes_genero = 'cualquiera'
  if (childForm.participantes_genero !== 'mixto' && childForm.participantes_min == null) {
    childForm.participantes_min = 1
  }
  if (childForm.participantes_genero === 'mixto') {
    if (childForm.participantes_min_m == null) childForm.participantes_min_m = 1
    if (childForm.participantes_min_f == null) childForm.participantes_min_f = 1
  }
}

watch(
  () => childOpts.controlParticipantes,
  (on) => {
    if (on) ensureChildParticipantQuotaDefaults()
  },
)

watch(
  () => childForm.permite_inscribir_no_participantes,
  (on) => {
    if (on) ensureChildParticipantQuotaDefaults()
  },
)

watch(
  () => childForm.participantes_genero,
  (genero) => {
    if (!childOpts.controlParticipantes || !childForm.permite_inscribir_no_participantes) return
    if (genero === 'mixto') {
      if (childForm.participantes_min_m == null) childForm.participantes_min_m = 1
      if (childForm.participantes_min_f == null) childForm.participantes_min_f = 1
    } else if (childForm.participantes_min == null) {
      childForm.participantes_min = 1
    }
  },
)

watch(
  () => opts.esConjunto,
  (on) => {
    form.es_conjunto = on
    if (on && !form.nivel_conjunto) form.nivel_conjunto = 'club'
    if (!on) form.nivel_conjunto = null
  },
)

watch(
  () => opts.manejaFechaFin,
  (on) => {
    if (on && !form.ends_at) {
      const start = resolveStartDate()
      form.ends_at = ensureEndAfterStart(start, dateOnly(contextEndsAt()) || addOneDay(start))
    }
  },
)

watch(
  () => opts.manejaPenalizaciones,
  (on) => {
    if (on && form.puntos_penalizacion == null) form.puntos_penalizacion = 5
  },
)

watch(
  () => opts.tieneValor,
  (on) => {
    if (on && form.precio == null) form.precio = 0
  },
)

watch(
  () => opts.requiereEvidencia,
  (on) => {
    if (on && !form.tipos_evidencia.length) {
      form.tipos_evidencia = ['link', 'pdf', 'imagen', 'audio', 'video']
    }
  },
)

watch(
  () => props.parentId,
  () => {
    navStack.value = []
    selectedId.value = null
    void load()
  },
)

watch(
  () => props.categoriasVersion,
  () => {
    void loadCategorias()
  },
)

watch(categoriasDisponibles, (list) => {
  if (!form.categoria_subevento_id) {
    if (!editingId.value && list[0]) form.categoria_subevento_id = list[0].id
    return
  }
  if (list.some((item) => item.id === form.categoria_subevento_id)) return
  if (editingId.value) return
  form.categoria_subevento_id = list[0]?.id ?? null
})

async function loadCategorias(): Promise<void> {
  try {
    const [cats, tipos, juecesList, supervisoresList, criterios] = await Promise.all([
      eventsService.categoriasSubevento(),
      eventsService.tipos(),
      eventsService.jueces(),
      eventsService.supervisores(),
      eventsService.criteriosEvaluacion(),
    ])
    categorias.value = cats
    tiposEvento.value = tipos
    jueces.value = juecesList
    supervisores.value = supervisoresList
    criteriosBank.value = criterios
  } catch {
    categorias.value = []
    tiposEvento.value = []
    jueces.value = []
    supervisores.value = []
    criteriosBank.value = []
  }
}

onMounted(async () => {
  await loadCategorias()
  await load()
})

onBeforeUnmount(() => {
  clearPendingImage()
  clearPendingChildImage()
  clearDragGhost()
  clearTimeout(pulseTimer)
  if (dropRaf) cancelAnimationFrame(dropRaf)
})
</script>

<template>
  <div class="sub-step">
    <div class="step-section-title">
      <i class="pi pi-share-alt" />
      <h2>{{ t('events.wizard.stepSubevents') }}</h2>
    </div>
    <p class="step-lead">{{ t('events.wizard.subeventsLead') }}</p>

    <Message v-if="!parentId" severity="info" :closable="false">
      {{ t('events.wizard.subeventsNeedSave') }}
    </Message>

    <template v-else>
      <nav v-if="isNested || parentName" class="sub-breadcrumb" aria-label="Breadcrumb">
        <button type="button" class="sub-breadcrumb__item" :class="{ 'is-current': !isNested }" @click="goBreadcrumb(-1)">
          {{ parentName || t('events.wizard.stepSubevents') }}
        </button>
        <template v-for="(node, index) in navStack" :key="node.id">
          <i class="pi pi-chevron-right sub-breadcrumb__sep" />
          <button
            type="button"
            class="sub-breadcrumb__item"
            :class="{ 'is-current': index === navStack.length - 1 }"
            @click="goBreadcrumb(index)"
          >
            {{ node.name }}
          </button>
        </template>
      </nav>

      <Message v-if="isNested" severity="info" :closable="false">
        {{ t('events.wizard.subNestedLead', { name: activeParentName }) }}
      </Message>

      <Message v-if="errorMessage" severity="error" :closable="true" @close="errorMessage = ''">
        {{ errorMessage }}
      </Message>

      <div class="sub-stats">
        <div class="sub-stat">
          <strong>{{ stats.total }}</strong>
          <span>{{ t('events.wizard.subStatTotal') }}</span>
        </div>
        <div class="sub-stat">
          <strong>{{ stats.puntos.toLocaleString() }} pts</strong>
          <span>{{ t('events.wizard.subStatPoints') }}</span>
        </div>
        <div class="sub-stat">
          <strong>{{ stats.activos }}</strong>
          <span>{{ t('events.wizard.subStatActive') }}</span>
        </div>
        <div class="sub-stat">
          <strong>{{ stats.borradores }}</strong>
          <span>{{ t('events.wizard.subStatDrafts') }}</span>
        </div>
      </div>

      <div class="sub-toolbar">
        <Button
          type="button"
          icon="pi pi-plus"
          :label="t('events.wizard.subAdd')"
          @click="openCreate"
        />
        <AppSearchField
          v-model="search"
          class="sub-search"
          :placeholder="t('events.wizard.subSearch')"
        />
      </div>

      <div class="sub-layout">
        <div class="sub-main">
          <PageLoader v-if="loading" :label="t('common.loading')" />

          <div v-else-if="!filtered.length" class="pj-muted empty">
            {{ t('events.wizard.subeventsEmpty') }}
          </div>

          <div v-else class="sub-table-wrap">
            <table class="sub-table">
              <thead>
                <tr>
                  <th>{{ t('events.wizard.subColOrder') }}</th>
                  <th>{{ t('events.wizard.subColName') }}</th>
                  <th class="col-score">{{ t('events.wizard.subColScore') }}</th>
                  <th class="col-status">{{ t('events.wizard.subColStatus') }}</th>
                  <th class="col-actions">{{ t('events.wizard.subColActions') }}</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="(item, index) in filtered" :key="item.id">
                  <tr
                    :class="[
                      {
                        'is-selected': selectedId === item.id,
                        'is-dragging': dragId === item.id,
                        'is-pulse': pulseId === item.id,
                      },
                      dropClassFor(item.id),
                    ]"
                    draggable="true"
                    @dragstart="onDragStartItem(item, $event)"
                    @dragend="onDragEndItem"
                    @dragover="onDragOverItem(item, $event)"
                    @drop="onDropItem(item, $event)"
                    @click="selectItem(item)"
                  >
                    <td class="col-orden">
                      <button
                        v-if="hasChildren(item)"
                        type="button"
                        class="sub-expand-btn"
                        :aria-expanded="isExpanded(item.id)"
                        :aria-label="t('events.wizard.subToggleChildren')"
                        @click.stop="toggleChildren(item.id)"
                      >
                        <i :class="isExpanded(item.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
                      </button>
                      <span>{{ item.orden || index + 1 }}</span>
                    </td>
                    <td>
                      <div class="sub-name">
                        <span
                          v-if="showsImage(item)"
                          class="sub-name__thumb"
                        >
                          <img :src="item.image_url" :alt="item.name" />
                        </span>
                        <span
                          v-else
                          class="sub-name__icon"
                          :style="iconStyleFor(item)"
                        >
                          <IconMark :icono="iconFor(item)" />
                        </span>
                        <div>
                          <strong>{{ item.name }}</strong>
                          <small v-if="item.descripcion">{{ item.descripcion }}</small>
                          <button
                            v-if="hasChildren(item)"
                            type="button"
                            class="sub-children-chip"
                            @click.stop="toggleChildren(item.id)"
                          >
                            {{ t('events.childrenCount', { count: childCount(item) }) }}
                          </button>
                        </div>
                      </div>
                    </td>
                    <td class="col-score">{{ Number(item.puntaje_maximo || 0) }} pts</td>
                    <td class="col-status">
                      <span
                        class="status-pill"
                        :class="item.estado === 'publicado' ? 'is-active' : 'is-draft'"
                      >
                        {{
                          item.estado === 'publicado'
                            ? t('events.estadoPublicado')
                            : t('events.estadoBorrador')
                        }}
                      </span>
                    </td>
                    <td class="col-actions" @click.stop>
                      <Button
                        v-if="hasChildren(item)"
                        type="button"
                        class="sub-action--desktop"
                        :icon="isExpanded(item.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'"
                        text
                        rounded
                        size="small"
                        v-tooltip.top="t('events.wizard.subToggleChildren')"
                        @click="toggleChildren(item.id)"
                      />
                      <Button
                        type="button"
                        class="sub-action--desktop"
                        icon="pi pi-plus"
                        text
                        rounded
                        size="small"
                        v-tooltip.top="t('events.wizard.subAddChild')"
                        @click="openCreateChild(item)"
                      />
                      <Button
                        type="button"
                        class="sub-action--desktop"
                        icon="pi pi-sitemap"
                        text
                        rounded
                        size="small"
                        v-tooltip.top="t('events.wizard.subOpenChildren')"
                        @click="enterChildren(item)"
                      />
                      <Button
                        type="button"
                        class="sub-action--desktop"
                        icon="pi pi-pencil"
                        text
                        rounded
                        size="small"
                        @click="openEdit(item)"
                      />
                      <Button
                        type="button"
                        class="sub-action--desktop"
                        icon="pi pi-copy"
                        text
                        rounded
                        size="small"
                        v-tooltip.top="t('events.wizard.subDuplicate')"
                        :loading="duplicatingId === item.id"
                        @click="duplicateSubevent(item)"
                      />
                      <Button
                        v-if="!hasChildren(item)"
                        type="button"
                        class="sub-action--desktop"
                        icon="pi pi-trash"
                        text
                        rounded
                        size="small"
                        severity="danger"
                        @click="removeSubevent(item)"
                      />
                      <Button
                        type="button"
                        class="sub-action--mobile"
                        icon="pi pi-ellipsis-v"
                        text
                        rounded
                        size="small"
                        :aria-label="t('common.moreActions')"
                        @click="toggleRowMenu(item, $event)"
                      />
                    </td>
                  </tr>
                  <tr
                    v-if="hasChildren(item) && isExpanded(item.id) && item.hijos?.length"
                    class="sub-nest-row"
                  >
                    <td colspan="5" class="sub-nest-cell">
                      <EventSubeventTreeNodes
                        :nodes="item.hijos"
                        :expanded="expandedChildren"
                        :selected-id="selectedId"
                        :drag-id="dragId"
                        :drop-target="dropTarget"
                        :pulse-id="pulseId"
                        @toggle="toggleChildren"
                        @select="selectItem"
                        @edit="openEdit"
                        @remove="removeSubevent"
                        @duplicate="duplicateSubevent"
                        @enter="enterChildren"
                        @add-child="openCreateChild"
                        @drag-start="onDragStartItem"
                        @drag-over="onDragOverItem"
                        @drop="onDropItem"
                        @drag-end="onDragEndItem"
                      />
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>

            <Menu ref="rowMenu" :model="rowMenuItems" popup />

            <div class="sub-table-footer">
              <span class="pj-muted">{{ t('events.wizard.subDragHint') }}</span>
              <span :class="['budget', { 'is-ok': budgetOk, 'is-over': !budgetOk }]">
                <i :class="budgetOk ? 'pi pi-check-circle' : 'pi pi-exclamation-triangle'" />
                {{ t('events.wizard.subBudget', { assigned: stats.puntos, total: budget || '—' }) }}
              </span>
            </div>
          </div>
        </div>

        <aside v-if="selected" class="sub-detail">
          <div v-if="showsImage(selected)" class="sub-detail__media">
            <img :src="selected.image_url" :alt="selected.name" />
          </div>
          <div class="sub-detail__head">
            <span
              v-if="!showsImage(selected)"
              class="sub-name__icon sub-name__icon--lg"
              :style="iconStyleFor(selected, 52)"
            >
              <IconMark :icono="iconFor(selected)" />
            </span>
            <div>
              <h3>{{ selected.name }}</h3>
              <span
                v-if="selected.categoria_subevento"
                class="cat-pill"
                :style="{
                  color: cssColor(selected.categoria_subevento.color),
                  borderColor: cssColor(selected.categoria_subevento.color),
                }"
              >
                {{ selected.categoria_subevento.nombre }}
              </span>
            </div>
            <Select
              :model-value="selected.estado"
              :options="estadoOptions"
              option-label="label"
              option-value="value"
              class="sub-detail__estado"
              @update:model-value="patchSelectedEstado"
            />
          </div>

          <div class="sub-tabs">
            <button type="button" :class="{ 'is-active': detailTab === 'info' }" @click="detailTab = 'info'">
              {{ t('events.wizard.subTabInfo') }}
            </button>
            <button type="button" :class="{ 'is-active': detailTab === 'reglas' }" @click="detailTab = 'reglas'">
              {{ t('events.wizard.subTabRules') }}
            </button>
            <button type="button" :class="{ 'is-active': detailTab === 'puntaje' }" @click="detailTab = 'puntaje'">
              {{ t('events.wizard.subTabScore') }}
            </button>
            <button
              type="button"
              :class="{ 'is-active': detailTab === 'categoria' }"
              @click="detailTab = 'categoria'"
            >
              {{ t('events.wizard.subTabCategory') }}
            </button>
          </div>

          <div v-show="detailTab === 'info'" class="sub-detail__body">
            <h4>{{ t('events.wizard.shortDescription') }}</h4>
            <p>{{ selected.descripcion || t('events.wizard.previewPending') }}</p>
            <ul class="meta-list">
              <li v-if="selected.puntaje_por_participar">
                <i class="pi pi-verified" />
                <span>{{ t('events.wizard.subOptScoreByParticipation') }}</span>
                <strong>{{ t('common.yes') }}</strong>
              </li>
              <li v-if="selected.puntaje_maximo != null || selected.es_calificable || selected.puntaje_desde_hijos">
                <i class="pi pi-star" />
                <span>{{ t('events.wizard.subColScore') }}</span>
                <strong>
                  {{ Number(selected.puntaje_maximo || 0) }} pts
                  <template v-if="selected.puntaje_desde_hijos">
                    · {{ t('events.wizard.subScoreFromChildrenBadge') }}
                  </template>
                </strong>
              </li>
              <li>
                <i class="pi pi-bookmark" />
                <span>{{ t('events.tipoEvento') }}</span>
                <strong>{{ selected.tipo_evento?.nombre || '—' }}</strong>
              </li>
              <li>
                <i class="pi pi-tag" />
                <span>{{ t('events.wizard.subColCategory') }}</span>
                <strong>{{ selected.categoria_subevento?.nombre || '—' }}</strong>
              </li>
              <li v-if="selected.requiere_puesto_entrega">
                <i class="pi pi-map-marker" />
                <span>{{ t('events.wizard.subPuestoEntrega') }}</span>
                <strong>{{ t('common.yes') }}</strong>
              </li>
              <li v-if="selected.requiere_tiempo_entrega">
                <i class="pi pi-clock" />
                <span>{{ t('events.wizard.subTiempoEntrega') }}</span>
                <strong>{{ t('common.yes') }}</strong>
              </li>
              <li v-if="selected.resultado_esperado != null">
                <i class="pi pi-check-square" />
                <span>{{ t('events.wizard.subResultadoEsperado') }}</span>
                <strong>{{ selected.resultado_esperado }}</strong>
              </li>
              <li
                v-if="
                  selected.participantes_min != null ||
                  selected.participantes_max != null ||
                  selected.participantes_genero != null
                "
              >
                <i class="pi pi-users" />
                <span>{{ t('events.wizard.subParticipants') }}</span>
                <strong>{{ participantesQuotaLabel(selected) }}</strong>
              </li>
              <li v-if="selected.es_conjunto">
                <i class="pi pi-share-alt" />
                <span>{{ t('events.wizard.subOptJoint') }}</span>
                <strong>{{ nivelConjuntoLabel(selected.nivel_conjunto) }}</strong>
              </li>
              <li v-if="selected.maneja_fecha_fin">
                <i class="pi pi-calendar-times" />
                <span>{{ t('events.endsAt') }}</span>
                <strong>
                  {{
                    selected.ends_at
                      ? formatDateOnly(selected.ends_at)
                      : '—'
                  }}
                </strong>
              </li>
              <li v-if="selected.maneja_penalizaciones">
                <i class="pi pi-exclamation-triangle" />
                <span>{{ t('events.wizard.subOptPenalties') }}</span>
                <strong>
                  −{{ Number(selected.puntos_penalizacion || 0) }} pts
                  <template v-if="selected.reglas_penalizacion">
                    · {{ selected.reglas_penalizacion }}
                  </template>
                </strong>
              </li>
              <li v-if="selected.requiere_pago || selected.precio != null">
                <i class="pi pi-dollar" />
                <span>{{ t('events.wizard.subOptValue') }}</span>
                <strong>{{ Number(selected.precio || 0).toLocaleString('es-ES', { style: 'currency', currency: 'USD' }) }}</strong>
              </li>
              <li v-if="selected.requiere_evidencia">
                <i class="pi pi-paperclip" />
                <span>{{ t('events.wizard.subOptEvidence') }}</span>
                <strong>{{ evidenciaTiposLabel(selected.tipos_evidencia) }}</strong>
              </li>
              <li>
                <i class="pi pi-user" />
                <span>{{ t('events.wizard.subJudge') }}</span>
                <strong>
                  {{
                    peopleNames(
                      selected.jueces_heredados
                        ? selected.jueces_efectivos
                        : selected.jueces,
                      selected.jueces_heredados
                        ? t('events.wizard.subJudgeInherited')
                        : t('events.wizard.subJudgeEmpty'),
                    )
                  }}
                </strong>
              </li>
              <li>
                <i class="pi pi-eye" />
                <span>{{ t('events.wizard.subSupervisor') }}</span>
                <strong>
                  {{
                    peopleNames(
                      selected.supervisores_heredados
                        ? selected.supervisores_efectivos
                        : selected.supervisores,
                      selected.supervisores_heredados
                        ? t('events.wizard.subSupervisorInherited')
                        : t('events.wizard.subSupervisorEmpty'),
                    )
                  }}
                </strong>
              </li>
            </ul>
            <div class="tips-mini">
              <i class="pi pi-lightbulb" />
              <p>{{ t('events.wizard.subTip') }}</p>
            </div>
          </div>

          <div v-show="detailTab === 'reglas'" class="sub-detail__body">
            <RichTextView :html="selected.reglas" :empty="t('events.wizard.subNoRules')" />
          </div>
          <div v-show="detailTab === 'puntaje'" class="sub-detail__body">
            <p>
              <strong>{{ Number(selected.puntaje_maximo || 0) }}</strong>
              {{ t('events.wizard.subPointsLabel') }}
            </p>
          </div>
          <div v-show="detailTab === 'categoria'" class="sub-detail__body">
            <p>{{ selected.categoria_subevento?.nombre || '—' }}</p>
          </div>

          <div class="sub-detail__actions">
            <Button
              type="button"
              :label="t('events.wizard.subOpenChildren')"
              icon="pi pi-sitemap"
              outlined
              @click="enterChildren(selected)"
            />
            <Button
              type="button"
              :label="t('events.wizard.subAddChild')"
              icon="pi pi-plus"
              outlined
              @click="openCreateChild(selected)"
            />
            <Button type="button" :label="t('common.edit')" icon="pi pi-pencil" outlined @click="openEdit(selected)" />
            <Button
              type="button"
              :label="t('events.wizard.subDuplicate')"
              icon="pi pi-copy"
              outlined
              :loading="!!selected && duplicatingId === selected.id"
              @click="duplicateSubevent(selected)"
            />
          </div>
        </aside>
      </div>
    </template>

    <AppStackDrawer
      v-model:visible="drawerVisible"
      :title="drawerTitle"
      :subtitle="drawerSubtitle"
      :level="1"
    >
      <div class="sub-form">
        <Message v-if="errorMessage" severity="error" :closable="true" @close="errorMessage = ''">
          {{ errorMessage }}
        </Message>
        <div class="sub-form__hero">
          <div class="field field--sub-cover">
            <div class="visual-toggle" role="tablist">
              <button
                type="button"
                :class="{ 'is-active': visualKind === 'imagen' }"
                @click="setVisualKind('imagen')"
              >
                {{ t('events.wizard.subVisualImage') }}
              </button>
              <button
                type="button"
                :class="{ 'is-active': visualKind === 'icono' }"
                @click="setVisualKind('icono')"
              >
                {{ t('events.wizard.subVisualIcon') }}
              </button>
            </div>
            <MediaCoverUpload
              v-if="visualKind === 'imagen'"
              compact
              :src="imagePreview"
              :title="t('events.wizard.subImage')"
              @select="onPickImage"
            />
            <IconColorPopover
              v-else
              variant="cover"
              v-model:icono="form.icono"
              v-model:color="form.color"
              v-model:tamano="form.icono_tamano"
            />
            <small class="pj-muted">{{ t('events.wizard.subVisualHint') }}</small>
          </div>
          <div class="sub-form__hero-fields">
            <div class="field">
              <label>{{ t('events.name') }}</label>
              <InputText v-model="form.name" class="w-full" />
            </div>
            <div class="field">
              <label>{{ t('events.wizard.shortDescription') }}</label>
              <Textarea v-model="form.descripcion" rows="3" class="w-full" auto-resize />
            </div>
          </div>
        </div>
        <div class="field">
          <label>{{ t('events.wizard.subTabRules') }}</label>
          <RichTextField v-model="form.reglas" />
          <small class="pj-muted">{{ t('events.wizard.subRulesHint') }}</small>
        </div>
        <div class="field-grid">
          <div class="field">
            <label>{{ t('events.tipoEvento') }}</label>
            <Select
              v-model="form.tipo_evento_id"
              :options="tiposEvento"
              option-label="nombre"
              option-value="id"
              show-clear
              :placeholder="t('events.tipoEventoPlaceholder')"
              class="w-full"
            />
          </div>
          <div v-if="selectedCategoria?.maneja_fecha_inicio" class="field">
            <label>{{ t('events.startsAt') }}</label>
            <DatePicker
              :model-value="form.starts_at"
              date-format="dd/mm/yy"
              class="w-full"
              :min-date="opts.esEnSitio ? parentMinDate : undefined"
              :max-date="opts.esEnSitio ? parentMaxDate : undefined"
              @update:model-value="(v) => (form.starts_at = dateOnly(Array.isArray(v) ? v[0] : v))"
            />
            <small v-if="opts.esEnSitio" class="pj-muted">{{ t('events.esEnSitioSubHint') }}</small>
          </div>
          <div class="field">
            <label>{{ t('events.workflowStatus') }}</label>
            <Select
              v-model="form.estado"
              :options="estadoOptions"
              option-label="label"
              option-value="value"
              class="w-full"
            />
          </div>
        </div>

        <EventSubeventConfigTabs
          :form="form"
          :opts="opts"
          :assigned-criterio-ids="assignedCriterioIds"
          :criterio-points="criterioPoints"
          :criterio-options="criterioOptions"
          :juez-options="juezOptions"
          :supervisor-options="supervisorOptions"
          :participantes-genero-options="participantesGeneroOptions"
          :nivel-conjunto-options="nivelConjuntoOptions"
          :materiales="materiales"
          :event-id="editingId"
          :children-score-sum="childrenScoreSum"
          :loading-children-score="loadingChildrenScore"
          :min-date="parentMinDate"
          :max-date="parentMaxDate"
          @update:assigned-criterio-ids="assignedCriterioIds = $event"
          @queued="pendingMaterialFiles = [...pendingMaterialFiles, ...$event]"
          @queued-youtube="pendingYoutube = [...pendingYoutube, $event]"
          @uploaded="materiales = [...materiales, $event]"
          @removed="materiales = materiales.filter((item) => item.id !== $event)"
        >
          <template #hijos>
            <div class="sub-option">
              <label class="sub-option__toggle">
                <ToggleSwitch v-model="opts.tieneSubeventos" />
                <span>{{ t('events.tieneSubeventos') }}</span>
              </label>
              <small class="pj-muted">{{ t('events.tieneSubeventosHint') }}</small>
            </div>
            <div v-if="opts.tieneSubeventos" class="sub-option__fields">
              <div class="children-toolbar">
                <Button
                  type="button"
                  icon="pi pi-plus"
                  :label="t('events.wizard.subChildrenAdd')"
                  size="small"
                  @click="openCreateNested"
                />
              </div>
              <p v-if="loadingChildren" class="pj-muted">{{ t('common.loading') }}</p>
              <p v-else-if="!childItems.length" class="pj-muted">{{ t('events.wizard.subChildrenEmpty') }}</p>
              <ul v-else class="children-list">
                <li v-for="child in childItems" :key="child.id">
                  <button type="button" class="children-list__item" @click="openEditNested(child)">
                    <strong>{{ child.name }}</strong>
                    <small>{{ formatDateOnly(child.starts_at) }}</small>
                  </button>
                </li>
              </ul>
            </div>
          </template>
        </EventSubeventConfigTabs>
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          text
          :disabled="saving || uploadingImage"
          @click="drawerVisible = false"
        />
        <Button
          :label="t('common.save')"
          icon="pi pi-check"
          :loading="saving || uploadingImage"
          @click="() => saveSubevent()"
        />
      </template>
    </AppStackDrawer>

    <AppStackDrawer
      v-model:visible="childDrawerVisible"
      :title="childEditingId ? t('events.wizard.subEdit') : t('events.wizard.subChildrenAdd')"
      :subtitle="form.name || activeParentName"
      :level="2"
    >
      <div class="sub-form">
        <Message v-if="childError" severity="error" :closable="true" @close="childError = ''">
          {{ childError }}
        </Message>
        <div class="sub-form__hero">
          <div class="field field--sub-cover">
            <div class="visual-toggle" role="tablist">
              <button
                type="button"
                :class="{ 'is-active': childVisualKind === 'imagen' }"
                @click="setChildVisualKind('imagen')"
              >
                {{ t('events.wizard.subVisualImage') }}
              </button>
              <button
                type="button"
                :class="{ 'is-active': childVisualKind === 'icono' }"
                @click="setChildVisualKind('icono')"
              >
                {{ t('events.wizard.subVisualIcon') }}
              </button>
            </div>
            <MediaCoverUpload
              v-if="childVisualKind === 'imagen'"
              compact
              :src="childImagePreview"
              :title="t('events.wizard.subImage')"
              @select="onPickChildImage"
            />
            <IconColorPopover
              v-else
              variant="cover"
              v-model:icono="childForm.icono"
              v-model:color="childForm.color"
              v-model:tamano="childForm.icono_tamano"
            />
            <small class="pj-muted">{{ t('events.wizard.subVisualHint') }}</small>
          </div>
          <div class="sub-form__hero-fields">
            <div class="field">
              <label>{{ t('events.name') }}</label>
              <InputText v-model="childForm.name" class="w-full" />
            </div>
            <div class="field">
              <label>{{ t('events.wizard.shortDescription') }}</label>
              <Textarea v-model="childForm.descripcion" rows="3" class="w-full" auto-resize />
            </div>
          </div>
        </div>
        <div class="field">
          <label>{{ t('events.wizard.subTabRules') }}</label>
          <RichTextField v-model="childForm.reglas" />
          <small class="pj-muted">{{ t('events.wizard.subRulesHint') }}</small>
        </div>
        <div class="field-grid">
          <div class="field">
            <label>{{ t('events.tipoEvento') }}</label>
            <Select
              v-model="childForm.tipo_evento_id"
              :options="tiposEvento"
              option-label="nombre"
              option-value="id"
              show-clear
              :placeholder="t('events.tipoEventoPlaceholder')"
              class="w-full"
            />
          </div>
          <div class="field">
            <label>{{ t('events.startsAt') }}</label>
            <DatePicker
              :model-value="childForm.starts_at"
              date-format="dd/mm/yy"
              class="w-full"
              :min-date="childOpts.esEnSitio ? childMinDate : undefined"
              :max-date="childOpts.esEnSitio ? childMaxDate : undefined"
              @update:model-value="(v) => (childForm.starts_at = dateOnly(Array.isArray(v) ? v[0] : v))"
            />
            <small v-if="childOpts.esEnSitio" class="pj-muted">{{ t('events.esEnSitioSubHint') }}</small>
          </div>
          <div class="field">
            <label>{{ t('events.workflowStatus') }}</label>
            <Select
              v-model="childForm.estado"
              :options="estadoOptions"
              option-label="label"
              option-value="value"
              class="w-full"
            />
          </div>
        </div>

        <EventSubeventConfigTabs
          :form="childForm"
          :opts="childOpts"
          :assigned-criterio-ids="childAssignedCriterioIds"
          :criterio-points="childCriterioPoints"
          :criterio-options="criterioOptions"
          :juez-options="juezOptions"
          :supervisor-options="supervisorOptions"
          :participantes-genero-options="participantesGeneroOptions"
          :nivel-conjunto-options="nivelConjuntoOptions"
          :materiales="childMateriales"
          :event-id="childEditingId"
          hide-children
          :show-score-from-children="false"
          :min-date="childOpts.esEnSitio ? childMinDate : undefined"
          :max-date="childOpts.esEnSitio ? childMaxDate : undefined"
          @update:assigned-criterio-ids="childAssignedCriterioIds = $event"
          @queued="childPendingMaterialFiles = [...childPendingMaterialFiles, ...$event]"
          @queued-youtube="childPendingYoutube = [...childPendingYoutube, $event]"
          @uploaded="childMateriales = [...childMateriales, $event]"
          @removed="childMateriales = childMateriales.filter((item) => item.id !== $event)"
        />
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          text
          :disabled="childSaving || saving || childUploadingImage"
          @click="childDrawerVisible = false"
        />
        <Button
          :label="t('common.save')"
          icon="pi pi-check"
          :loading="childSaving || saving || childUploadingImage"
          @click="saveChildSubevent"
        />
      </template>
    </AppStackDrawer>
    <JuezPropagateDialog
      v-model:visible="juezConflictVisible"
      :conflicts="juezConflicts"
      :applying="juezConflictApplying"
      @apply="applyJuezConflicts"
      @dismiss="dismissJuezConflicts"
    />
  </div>
</template>

<style scoped>
.sub-step {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.step-section-title {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.step-section-title i {
  color: var(--pj-primary, #2563eb);
}

.step-section-title h2 {
  margin: 0;
  font-size: 1.05rem;
}

.step-lead {
  margin: 0;
  color: var(--pj-text-muted);
  font-size: 0.9rem;
}

.sub-breadcrumb {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.85rem;
}

.sub-breadcrumb__item {
  border: 0;
  background: transparent;
  color: var(--pj-primary, #2563eb);
  cursor: pointer;
  padding: 0.15rem 0.25rem;
  border-radius: 6px;
  font: inherit;
}

.sub-breadcrumb__item:hover {
  background: color-mix(in srgb, var(--pj-primary, #2563eb) 10%, transparent);
}

.sub-breadcrumb__item.is-current {
  color: var(--pj-text, #111827);
  font-weight: 600;
  cursor: default;
  pointer-events: none;
}

.sub-breadcrumb__sep {
  font-size: 0.65rem;
  color: var(--pj-text-muted);
}

.sub-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.65rem;
}

.sub-stat {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  padding: 0.75rem 0.9rem;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.sub-stat strong {
  font-size: 1.15rem;
}

.sub-stat span {
  font-size: 0.78rem;
  color: var(--pj-text-muted);
}

.sub-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: center;
}

.sub-search {
  min-width: 0;
  flex: 1 0 100%;
}

.sub-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(240px, 0.85fr);
  gap: 0.9rem;
  align-items: start;
}

.sub-main,
.sub-detail {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  background: #fff;
  min-width: 0;
}

.sub-table-wrap {
  overflow: auto;
}

.sub-table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 0.88rem;
}

.sub-table th,
.sub-table td {
  padding: 0.7rem 0.75rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  text-align: left;
  vertical-align: middle;
}

.sub-table th {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--pj-text-muted);
  background: color-mix(in srgb, var(--pj-navy) 3%, #fff);
}

.sub-table th:nth-child(1),
.sub-table td.col-orden {
  width: 3.5rem;
}

.sub-table th.col-score {
  width: 5.5rem;
}

.sub-table th.col-status {
  width: 7rem;
}

.sub-table th:nth-child(5),
.sub-table td.col-actions {
  width: 11rem;
}

.sub-table tbody tr {
  cursor: grab;
  transition: background-color 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
}

.sub-table tbody tr:hover,
.sub-table tbody tr.is-selected {
  background: color-mix(in srgb, #2563eb 12%, var(--pj-bg-elevated));
}

.sub-table tbody tr.is-dragging {
  opacity: 0.4;
}

.sub-table tbody tr.is-drop-before {
  box-shadow: inset 0 3px 0 0 #2563eb;
  background: color-mix(in srgb, #2563eb 10%, var(--pj-bg-elevated));
}

.sub-table tbody tr.is-drop-after {
  box-shadow: inset 0 -3px 0 0 #2563eb;
  background: color-mix(in srgb, #2563eb 10%, var(--pj-bg-elevated));
}

.sub-table tbody tr.is-drop-into {
  background: color-mix(in srgb, #2563eb 16%, var(--pj-bg-elevated));
  box-shadow: inset 0 0 0 2px #2563eb;
}

.sub-table tbody tr.is-pulse {
  animation: sub-pulse 0.65s ease;
}

@keyframes sub-pulse {
  0% {
    background: color-mix(in srgb, #2563eb 22%, var(--pj-bg-elevated));
  }
  100% {
    background: transparent;
  }
}

.col-orden {
  white-space: nowrap;
  color: var(--pj-text-muted);
}

.sub-expand-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.4rem;
  height: 1.4rem;
  margin-right: 0.2rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--pj-text-muted);
  cursor: pointer;
}

.sub-expand-btn:hover {
  background: color-mix(in srgb, var(--pj-border) 45%, transparent);
  color: var(--pj-text);
}

.sub-children-chip {
  display: inline-flex;
  margin-top: 0.25rem;
  padding: 0.1rem 0.45rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: color-mix(in srgb, var(--pj-navy) 6%, #fff);
  color: var(--pj-navy);
  font-size: 0.7rem;
  cursor: pointer;
}

.sub-nest-row td {
  padding: 0 0.75rem 0.75rem;
  background: color-mix(in srgb, var(--pj-bg) 88%, #fff);
}

.sub-nest-cell {
  border-top: 0 !important;
  overflow: hidden;
  max-width: 100%;
}

.sub-name {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
}

.sub-name__icon {
  width: 2rem;
  height: 2rem;
  border-radius: 8px;
  display: grid;
  place-content: center;
  border: 1px solid transparent;
  flex-shrink: 0;
}

.sub-name__icon i {
  color: inherit;
}

.sub-name__icon--lg {
  width: 3.75rem;
  height: 3.75rem;
}

.sub-name__thumb {
  width: 2.35rem;
  height: 2.35rem;
  border-radius: 8px;
  overflow: hidden;
  flex-shrink: 0;
  background: color-mix(in srgb, var(--pj-navy) 6%, #e2e8f0);
}

.sub-name__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.sub-name strong {
  display: block;
  font-size: 0.9rem;
}

.sub-name small {
  display: block;
  color: var(--pj-text-muted);
  font-size: 0.75rem;
  max-width: 18rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.cat-pill {
  display: inline-flex;
  border: 1px solid;
  border-radius: 999px;
  padding: 0.15rem 0.55rem;
  font-size: 0.72rem;
  font-weight: 700;
  background: transparent;
}

.status-pill {
  display: inline-flex;
  border-radius: 999px;
  padding: 0.15rem 0.55rem;
  font-size: 0.72rem;
  font-weight: 700;
}

.status-pill.is-active {
  background: color-mix(in srgb, #16a34a 14%, transparent);
  color: #15803d;
}

.status-pill.is-draft {
  background: color-mix(in srgb, #f59e0b 16%, transparent);
  color: #b45309;
}

.col-actions {
  white-space: nowrap;
}

.sub-action--mobile {
  display: none;
}

.sub-table-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.7rem 0.85rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  font-size: 0.82rem;
}

.budget {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 600;
}

.budget.is-ok {
  color: #15803d;
}

.budget.is-over {
  color: #b45309;
}

.sub-detail {
  padding: 0.9rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.sub-detail__media {
  border-radius: 12px;
  overflow: hidden;
  aspect-ratio: 16 / 9;
  background: color-mix(in srgb, var(--pj-navy) 8%, #e2e8f0);
}

.sub-detail__media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.sub-detail__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.65rem;
}

.sub-detail__head h3 {
  margin: 0 0 0.25rem;
  font-size: 1rem;
}

.sub-detail__estado {
  margin-left: auto;
  min-width: 8rem;
}

.sub-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  padding-bottom: 0.35rem;
}

.sub-tabs button {
  border: 0;
  background: transparent;
  padding: 0.35rem 0.55rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--pj-text-muted);
  cursor: pointer;
  border-radius: 8px;
}

.sub-tabs button.is-active {
  color: var(--pj-primary, #2563eb);
  background: color-mix(in srgb, #2563eb 10%, transparent);
}

.sub-tabs--options button.has-config::after {
  content: '';
  display: inline-block;
  width: 0.42rem;
  height: 0.42rem;
  margin-left: 0.35rem;
  border-radius: 50%;
  background: #16a34a;
  vertical-align: middle;
}

.sub-detail__body h4 {
  margin: 0 0 0.35rem;
  font-size: 0.82rem;
}

.sub-detail__body p {
  margin: 0 0 0.75rem;
  font-size: 0.88rem;
  color: color-mix(in srgb, var(--pj-text) 85%, transparent);
}

.meta-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.meta-list li {
  display: grid;
  grid-template-columns: 1.1rem 1fr auto;
  gap: 0.45rem;
  align-items: center;
  font-size: 0.84rem;
}

.meta-list i {
  color: var(--pj-primary, #2563eb);
}

.tips-mini {
  margin-top: 0.85rem;
  display: flex;
  gap: 0.45rem;
  padding: 0.7rem 0.8rem;
  border-radius: 10px;
  background: color-mix(in srgb, #fbbf24 12%, #fff);
  border: 1px solid color-mix(in srgb, #f59e0b 25%, transparent);
  font-size: 0.8rem;
}

.tips-mini i {
  color: #d97706;
}

.tips-mini p {
  margin: 0;
}

.sub-detail__actions {
  margin-top: auto;
  padding-top: 0.5rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.sub-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.sub-form__hero {
  display: grid;
  grid-template-columns: minmax(10rem, 14rem) minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}

.field--sub-cover {
  max-width: 14rem;
}

.visual-toggle {
  display: flex;
  margin-bottom: 0.45rem;
  padding: 0.15rem;
  border-radius: 9px;
  background: color-mix(in srgb, var(--pj-navy) 6%, #fff);
}

.visual-toggle button {
  flex: 1;
  border: 0;
  background: transparent;
  border-radius: 7px;
  padding: 0.32rem 0.4rem;
  font: inherit;
  font-size: 0.76rem;
  font-weight: 650;
  color: var(--pj-text-muted);
  cursor: pointer;
}

.visual-toggle button.is-active {
  background: #fff;
  color: var(--pj-navy);
  box-shadow: 0 1px 3px color-mix(in srgb, var(--pj-navy) 12%, transparent);
}

.sub-form__hero-fields {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-width: 0;
}

@media (max-width: 640px) {
  .sub-form__hero {
    grid-template-columns: 1fr;
  }
}

.sub-options {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding-top: 0.35rem;
  border-top: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
}

.sub-options__lead {
  margin: 0;
  font-size: 0.82rem;
  color: var(--pj-text-muted);
}

.sub-options__pane {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.sub-option {
  border: 1px solid color-mix(in srgb, var(--pj-border) 75%, transparent);
  border-radius: 12px;
  padding: 0.65rem 0.75rem;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.sub-option__toggle {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  cursor: pointer;
  font-size: 0.88rem;
  font-weight: 600;
}

.sub-option__fields {
  padding-top: 0.15rem;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.criteria-sum--bad {
  color: #b91c1c;
  font-weight: 600;
}

.crit-opt {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
}

.children-toolbar {
  display: flex;
  justify-content: flex-end;
}

.children-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.4rem;
}

.children-list__item {
  width: 100%;
  display: flex;
  justify-content: space-between;
  gap: 0.65rem;
  align-items: center;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--pj-border);
  border-radius: 10px;
  background: var(--pj-bg-elevated, transparent);
  cursor: pointer;
  text-align: left;
}

.children-list__item strong,
.children-list__item small {
  display: block;
}

.children-list__item small {
  color: var(--pj-text-muted);
  font-size: 0.75rem;
}

.sub-option__nested {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  cursor: pointer;
  font-size: 0.84rem;
  font-weight: 600;
}

.evidence-types {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.evidence-type {
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  background: transparent;
  border-radius: 999px;
  padding: 0.35rem 0.75rem;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  color: var(--pj-text-muted);
}

.evidence-type.is-active {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  border-color: #2563eb;
  color: #1d4ed8;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.field--wide {
  grid-column: 1 / -1;
}

.w-full {
  width: 100%;
}

.sub-dropzone {
  position: relative;
  display: block;
  border: 1.5px dashed color-mix(in srgb, var(--pj-border) 90%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, var(--pj-navy) 3%, #fff);
  min-height: 2.5rem;
  overflow: hidden;
  cursor: pointer;
}

.sub-dropzone:hover {
  border-color: var(--pj-primary, #2563eb);
}

.sub-dropzone img {
  width: 100%;
  height: 5.5rem;
  object-fit: cover;
  display: block;
}

.sub-dropzone__empty {
  display: grid;
  place-content: center;
  gap: 0.1rem;
  text-align: center;
  padding: 0.55rem 0.65rem;
  color: var(--pj-text-muted);
}

.sub-dropzone__empty i {
  font-size: 0.95rem;
  color: var(--pj-primary, #2563eb);
}

.sub-dropzone__empty strong {
  font-size: 0.72rem;
  color: var(--pj-text, #0f172a);
}

.sub-dropzone__empty span {
  font-size: 0.62rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

.empty {
  padding: 1.25rem;
}

@media (max-width: 1100px) {
  .sub-layout {
    grid-template-columns: 1fr;
  }

  .sub-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 900px) {
  .sub-table {
    table-layout: auto;
  }

  .sub-table th.col-score,
  .sub-table td.col-score,
  .sub-table th.col-status,
  .sub-table td.col-status,
  .sub-action--desktop {
    display: none;
  }

  .sub-action--mobile {
    display: inline-flex;
  }

  .sub-table th.col-actions,
  .sub-table td.col-actions {
    width: 2.6rem;
    min-width: 2.6rem;
    padding-left: 0.15rem;
    padding-right: 0.2rem;
  }

  .sub-table th.col-actions {
    font-size: 0;
  }

  .sub-table th,
  .sub-table td {
    padding: 0.5rem 0.4rem;
  }

  .sub-name {
    min-width: 0;
    width: 100%;
  }

  .sub-name small {
    display: none;
  }

  .sub-name strong {
    font-size: 0.84rem;
    line-height: 1.3;
    display: block;
    white-space: normal;
    overflow: visible;
    word-break: break-word;
  }

  .col-actions :deep(.p-button) {
    width: 2rem;
    height: 2rem;
  }
}

@media (max-width: 640px) {
  .sub-stats {
    grid-template-columns: 1fr;
  }

  .field-grid {
    grid-template-columns: 1fr;
  }

  .sub-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .sub-search {
    margin-left: 0;
    max-width: none;
  }

  .sub-table th:nth-child(1),
  .sub-table td.col-orden {
    width: 2.6rem;
  }

  .sub-table-footer {
    flex-wrap: wrap;
    gap: 0.4rem;
  }
}
</style>
