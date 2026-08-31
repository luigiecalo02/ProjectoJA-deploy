<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import Paginator from 'primevue/paginator'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import EventChildrenAccordion from '@/components/events/EventChildrenAccordion.vue'
import EventBannerCard from '@/components/events/EventBannerCard.vue'
import EventEstadoSelect from '@/components/events/EventEstadoSelect.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage, resolveFileUrl } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import { useAuthStore } from '@/stores/auth'
import type { ClubEvent } from '@/modules/events/types'
import type { PaginationMeta } from '@/types/api'
import {
  TIPO_CLUB,
  TIPOS_HIJO_CLUB,
} from '@/modules/organizaciones/types'
import { audienceKeyFromTipo } from '@/modules/events/audienceTipo'
import { cssColor } from '@/utils/color'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can, canCatalog } = usePermission()
const auth = useAuthStore()
const canCreateEvent = computed(() => can('events.create'))

usePageChrome(() => ({
  title: t('events.title'),
  subtitle: t('events.subtitle'),
  actions: canCreateEvent.value
    ? [
        {
          key: 'new',
          label: t('events.new'),
          icon: 'pi pi-plus',
          onClick: () => void router.push({ name: 'events.create' }),
        },
      ]
    : [],
}))

const events = ref<ClubEvent[]>([])
const loading = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<ClubEvent | null>(null)
const deleting = ref(false)
const menuRefs = ref<Record<number, InstanceType<typeof Menu> | null>>({})
const menuEventId = ref<number | null>(null)
const expandedChildren = ref<Set<number>>(new Set())

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (value: boolean) => {
    if (!value) deleteTarget.value = null
  },
})

const VIEW_STORAGE_KEY = 'pj.events.listView'

function readViewMode(): 'tree' | 'banner' {
  try {
    return localStorage.getItem(VIEW_STORAGE_KEY) === 'tree' ? 'tree' : 'banner'
  } catch {
    return 'banner'
  }
}

const isEventsAdmin = computed(() => {
  const roles = auth.user?.roles ?? []
  return (
    Boolean(auth.user?.is_super || auth.user?.is_admin) ||
    roles.includes('super_admin') ||
    roles.includes('admin')
  )
})

const viewMode = ref<'tree' | 'banner'>(readViewMode())
const listView = computed(() => (isEventsAdmin.value ? viewMode.value : 'banner'))

watch(viewMode, (mode) => {
  if (!isEventsAdmin.value) return
  try {
    localStorage.setItem(VIEW_STORAGE_KEY, mode)
  } catch {
    /* ignore */
  }
})

const filters = reactive({
  search: '',
  estado: null as string | null,
  audiencia: null as string | null,
  fecha: 'proximos' as string | null,
  page: 1,
  per_page: 20,
})

const estadoFilterOptions = computed(() => [
  { label: t('events.filterEstadoTodos'), value: null },
  { label: t('events.estadoPublicado'), value: 'publicado' },
  { label: t('events.estadoEnProceso'), value: 'en_proceso' },
  { label: t('events.estadoBorrador'), value: 'borrador' },
  { label: t('events.estadoCerrado'), value: 'cerrado' },
  { label: t('events.estadoCancelado'), value: 'cancelado' },
])

const audienciaFilterOptions = computed(() => [
  { label: t('events.filterAudienciaTodos'), value: null },
  { label: t('events.audienceConquistadores'), value: 'conquistadores' },
  { label: t('events.audienceAventureros'), value: 'aventureros' },
  { label: t('events.audienceGuias'), value: 'guias_mayores' },
  { label: t('events.audienceLibre'), value: 'libre' },
])

const fechaFilterOptions = computed(() => [
  { label: t('events.filterFechaProximos'), value: 'proximos' },
  { label: t('events.filterFechaPasados'), value: 'pasados' },
  { label: t('events.filterFechaTodos'), value: null },
])

const rangeLabel = computed(() => {
  const total = pagination.value?.total ?? events.value.length
  if (!total) return t('events.showingEmpty')
  const from = ((pagination.value?.current_page ?? 1) - 1) * (pagination.value?.per_page ?? filters.per_page) + 1
  const to = Math.min(from + (pagination.value?.per_page ?? filters.per_page) - 1, total)
  return t('events.showingRange', { from, to, total })
})

const isClubDirectorContext = computed(() => {
  const ctx = auth.contexto
  if (!ctx?.organizacion_id) return false
  const tipoId = ctx.tipo_organizacion_id
  const isClubTipo =
    tipoId === TIPO_CLUB ||
    (tipoId != null && (TIPOS_HIJO_CLUB as readonly number[]).includes(tipoId))
  const role = (ctx.rol_name || '').toLowerCase()
  return isClubTipo && (role === 'director' || role === 'subdirector')
})

function canEnroll(event: ClubEvent): boolean {
  const start = new Date(event.starts_at)
  const enrollmentClosed =
    !Number.isNaN(start.getTime()) &&
    Date.now() >
      new Date(start.getFullYear(), start.getMonth(), start.getDate(), 23, 59, 59, 999).getTime()

  return (
    isClubDirectorContext.value &&
    !!event.permite_inscripcion_club &&
    event.estado === 'publicado' &&
    !enrollmentClosed
  )
}

function canReviewInscripciones(_event: ClubEvent): boolean {
  return can('events.update')
}

function canAccessDistribucion(event: ClubEvent): boolean {
  if (isClubDirectorContext.value) return event.puede_elegir_lote === true
  return canCatalog('terrenos', 'view') || can('terrenos.assign')
}

function distribucionButtonLabel(): string {
  return isClubDirectorContext.value ? t('terrenos.elegirLote') : t('terrenos.distribucion')
}

function canAccessAlojamiento(event: ClubEvent): boolean {
  return event.puede_elegir_cama === true
}

function goEnroll(event: ClubEvent): void {
  router.push({
    name: 'events.enroll',
    params: { id: event.id },
    query: event.inscripcion_id ? { inscripcion_id: String(event.inscripcion_id) } : undefined,
  })
}

function goInscripcionesRevision(event: ClubEvent): void {
  router.push({ name: 'events.inscripcionesRevision', params: { id: event.id } })
}

function inscripcionStatusMeta(estado: string | null | undefined): { label: string; css: string } | null {
  if (!estado) return null
  const cssMap: Record<string, string> = {
    pendiente_revision: 'insc--pending',
    en_revision: 'insc--review',
    aprobada: 'insc--approved',
    no_aprobada: 'insc--rejected',
  }
  return {
    label: t(`events.revisionEstado.${estado}`, estado),
    css: cssMap[estado] || 'insc--default',
  }
}

function enrollButtonLabel(event: ClubEvent): string {
  if (event.inscripcion_estado === 'aprobada') {
    return t('events.enrollModify')
  }
  if (event.inscripcion_estado && event.inscripcion_estado !== 'aprobada') {
    return t('events.enrollContinue')
  }
  return t('events.enroll')
}

function canParticipate(event: ClubEvent): boolean {
  return isClubDirectorContext.value && (event.estado === 'publicado' || event.estado === 'en_proceso')
}

function eventHasScoring(event: ClubEvent): boolean {
  if (event.es_calificable || event.puntaje_maximo != null || event.puntaje_desde_hijos) {
    return true
  }
  return (event.hijos ?? []).some((child) => eventHasScoring(child))
}

function canJudge(event: ClubEvent): boolean {
  if (!eventHasScoring(event)) return false
  if (!can('events.evaluate') || (event.estado !== 'publicado' && event.estado !== 'en_proceso')) return false
  const ids = event.juez_ids
  if (!ids || ids.length === 0) return true
  const uid = auth.user?.id
  return uid != null && ids.includes(uid)
}

function canViewStandings(event: ClubEvent): boolean {
  if (!eventHasScoring(event)) return false
  return (
    (can('events.view_scores') || can('events.evaluate')) &&
    (event.estado === 'publicado' || event.estado === 'en_proceso' || event.estado === 'cerrado')
  )
}

function goJudge(event: ClubEvent, subeventoId?: number): void {
  router.push({
    name: 'events.judge',
    params: { id: event.id },
    query: subeventoId ? { subevento_id: String(subeventoId) } : undefined,
  })
}

function goParticipate(event: ClubEvent, subeventoId?: number): void {
  router.push({
    name: 'events.participate',
    params: { id: event.id },
    query: subeventoId ? { evento_id: String(subeventoId) } : undefined,
  })
}

function goStandings(event: ClubEvent): void {
  router.push({ name: 'events.standings', params: { id: event.id } })
}

function goStandingsTree(event: ClubEvent): void {
  router.push({ name: 'events.standings2', params: { id: event.id } })
}

const listMode = computed<'judge' | 'director' | 'default'>(() => {
  if (isClubDirectorContext.value) return 'director'
  if (can('events.evaluate')) return 'judge'
  return 'default'
})

function onTreeNodeOpen(node: ClubEvent, rootId: number): void {
  if (listMode.value === 'judge') {
    goJudge({ id: rootId } as ClubEvent, node.id)
    return
  }
  if (listMode.value === 'director') {
    goParticipate({ id: rootId } as ClubEvent, node.id)
  }
}

function cupoLabel(event: ClubEvent): string {
  if (event.cupo_ilimitado) return t('events.wizard.cupoIlimitado')
  if (event.cupo_maximo) return String(event.cupo_maximo)
  return '—'
}

function enrollmentLabel(event: ClubEvent): string {
  if (!event.requiere_pago || event.precio == null) return '—'
  return Number(event.precio).toLocaleString('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  })
}

function audienceSummary(event: ClubEvent): string {
  return audienceBadges(event).map((badge) => badge.label).join(', ')
}

function formatDateRange(startsAt: string, endsAt: string): string {
  const start = new Date(startsAt)
  const end = new Date(endsAt)
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return '—'
  const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }
  return `${start.toLocaleDateString('es-ES', opts)} — ${end.toLocaleDateString('es-ES', opts)}`
}

function audienceBadges(event: ClubEvent): Array<{ key: string; label: string; css: string }> {
  const tipos = event.tipos_organizacion ?? []
  if (!tipos.length) {
    return [{ key: 'libre', label: t('events.audienceLibre'), css: 'badge--all' }]
  }
  return tipos.map((tipo) => {
    const key = audienceKeyFromTipo(tipo.id, tipo.nombre)
    if (key === 'conquistadores') {
      return { key: `c-${tipo.id}`, label: t('events.audienceConquistadores'), css: 'badge--conquistadores' }
    }
    if (key === 'aventureros') {
      return { key: `a-${tipo.id}`, label: t('events.audienceAventureros'), css: 'badge--aventureros' }
    }
    if (key === 'guias_mayores') {
      return { key: `g-${tipo.id}`, label: t('events.audienceGuias'), css: 'badge--guias' }
    }
    return { key: `t-${tipo.id}`, label: tipo.nombre, css: 'badge--default' }
  })
}

const updatingEstadoId = ref<number | null>(null)

function canChangeEstado(): boolean {
  return isEventsAdmin.value
}

async function changeEventEstado(event: ClubEvent, estado: string): Promise<void> {
  if (!canChangeEstado()) return
  if (estado !== 'borrador' && estado !== 'publicado' && estado !== 'en_proceso' && estado !== 'cerrado') return
  if (event.estado === estado) return
  updatingEstadoId.value = event.id
  try {
    const saved = await eventsService.updateEstado(event.id, estado)
    event.estado = saved.estado
    event.is_active = saved.is_active
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.estadoUpdated'),
      life: 2200,
    })
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    updatingEstadoId.value = null
  }
}

function estadoMeta(event: ClubEvent): { label: string; css: string } {
  const estado = event.estado || (event.is_active ? 'publicado' : 'borrador')
  if (estado === 'publicado' && event.is_active) {
    return { label: t('events.estadoPublicado'), css: 'status--publicado' }
  }
  if (estado === 'en_proceso') {
    return { label: t('events.estadoEnProceso'), css: 'status--en-proceso' }
  }
  if (estado === 'borrador') {
    return { label: t('events.estadoBorrador'), css: 'status--borrador' }
  }
  if (estado === 'cerrado') {
    return { label: t('events.estadoCerrado'), css: 'status--cerrado' }
  }
  if (estado === 'cancelado') {
    return { label: t('events.estadoCancelado'), css: 'status--cancelado' }
  }
  return { label: estado, css: 'status--default' }
}

function categoryIcon(event: ClubEvent): string {
  if (event.tipo_evento?.icono) return event.tipo_evento.icono
  const badges = audienceBadges(event)
  if (badges.some((b) => b.css.includes('conquistadores'))) return 'pi pi-flag'
  if (badges.some((b) => b.css.includes('aventureros'))) return 'pi pi-sun'
  if (badges.some((b) => b.css.includes('guias'))) return 'pi pi-star'
  return 'pi pi-calendar'
}

function categoryTone(event: ClubEvent): string {
  const badges = audienceBadges(event)
  if (badges.some((b) => b.css.includes('conquistadores'))) return 'tone--conquistadores'
  if (badges.some((b) => b.css.includes('aventureros'))) return 'tone--aventureros'
  if (badges.some((b) => b.css.includes('guias'))) return 'tone--guias'
  return 'tone--default'
}

function matchesAudiencia(event: ClubEvent): boolean {
  if (!filters.audiencia) return true
  const badges = audienceBadges(event)
  if (filters.audiencia === 'libre' || filters.audiencia === 'todos') {
    return badges.some((b) => b.key === 'libre' || b.key === 'todos')
  }
  if (filters.audiencia === 'conquistadores') {
    return badges.some((b) => b.css.includes('conquistadores'))
  }
  if (filters.audiencia === 'aventureros') {
    return badges.some((b) => b.css.includes('aventureros'))
  }
  if (filters.audiencia === 'guias_mayores') {
    return badges.some((b) => b.css.includes('guias'))
  }
  return true
}

function matchesFecha(event: ClubEvent): boolean {
  if (!filters.fecha) return true
  const end = new Date(event.ends_at).getTime()
  const now = Date.now()
  if (filters.fecha === 'proximos') return end >= now
  if (filters.fecha === 'pasados') return end < now
  return true
}

const visibleEvents = computed(() =>
  events.value.filter((e) => {
    if (e.evento_padre_id) return false
    if (!isEventsAdmin.value) return true
    return matchesAudiencia(e) && matchesFecha(e)
  }),
)

async function loadEvents(): Promise<void> {
  loading.value = true
  try {
    const result = await eventsService.list({
      page: filters.page,
      per_page: isEventsAdmin.value ? filters.per_page : 20,
      search: isEventsAdmin.value ? filters.search || undefined : undefined,
      estado: isEventsAdmin.value ? filters.estado || undefined : undefined,
      solo_raiz: true,
    })
    events.value = result.items
    pagination.value = result.pagination
    // Expandir raíces con hijos para ver el árbol de una vez.
    const next = new Set(expandedChildren.value)
    for (const ev of result.items) {
      if ((ev.hijos_count ?? ev.hijos?.length ?? 0) > 0) {
        next.add(ev.id)
      }
    }
    expandedChildren.value = next
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

function childrenCount(event: ClubEvent): number {
  return event.hijos_count ?? event.hijos?.length ?? 0
}

function hasChildren(event: ClubEvent): boolean {
  return childrenCount(event) > 0
}

function isExpanded(eventId: number): boolean {
  return expandedChildren.value.has(eventId)
}

function toggleRootChildren(eventId: number): void {
  const next = new Set(expandedChildren.value)
  if (next.has(eventId)) next.delete(eventId)
  else next.add(eventId)
  expandedChildren.value = next
}

function toggleTreeNode(id: number): void {
  const next = new Set(expandedChildren.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expandedChildren.value = next
}

function onPage(event: { page: number; rows: number }): void {
  filters.page = event.page + 1
  filters.per_page = event.rows
  void loadEvents()
}

function menuItemsFor(event: ClubEvent): MenuItem[] {
  const items: MenuItem[] = []
  if (can('events.update')) {
    items.push({
      label: t('common.edit'),
      icon: 'pi pi-pencil',
      command: () => router.push({ name: 'events.edit', params: { id: event.id } }),
    })
  }
  if (can('events.update')) {
    items.push({
      label: t('events.revisionMenu'),
      icon: 'pi pi-inbox',
      command: () => router.push({ name: 'events.inscripcionesRevision', params: { id: event.id } }),
    })
  }
  if (canAccessDistribucion(event)) {
    items.push({
      label: distribucionButtonLabel(),
      icon: 'pi pi-map',
      command: () => router.push({ name: 'events.distribucion', params: { id: event.id } }),
    })
  }
  if (canAccessAlojamiento(event)) {
    items.push({
      label: event.alojamiento_asignado ? t('alojamiento.change') : t('alojamiento.action'),
      icon: 'pi pi-building',
      command: () => router.push({ name: 'events.alojamiento', params: { id: event.id } }),
    })
  }
  if (can('events.create')) {
    items.push({
      label: t('events.duplicate'),
      icon: 'pi pi-copy',
      command: () => {
        void duplicateEvent(event)
      },
    })
  }
  if (can('events.delete')) {
    items.push({
      label: t('common.delete'),
      icon: 'pi pi-trash',
      command: () => {
        deleteTarget.value = event
      },
    })
  }
  return items
}

const duplicatingId = ref<number | null>(null)

async function duplicateEvent(event: ClubEvent): Promise<void> {
  if (duplicatingId.value) return
  duplicatingId.value = event.id
  try {
    const cloned = await eventsService.duplicate(event.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.duplicateSuccess'),
      life: 2500,
    })
    await loadEvents()
    await router.push({ name: 'events.edit', params: { id: cloned.id } })
  } catch (error) {
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

function toggleMenu(eventId: number, e: Event): void {
  menuEventId.value = eventId
  const menu = menuRefs.value[eventId]
  menu?.toggle(e)
}

function setMenuRef(id: number, el: unknown): void {
  menuRefs.value[id] = (el as InstanceType<typeof Menu>) || null
}

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await eventsService.remove(deleteTarget.value.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.deleteSuccess'),
      life: 2500,
    })
    deleteTarget.value = null
    await loadEvents()
  } catch (error) {
    toast.add({
      severity: 'error',
      summary: t('common.error'),
      detail: getApiErrorMessage(error),
      life: 4000,
    })
  } finally {
    deleting.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(
  () => [filters.search, filters.estado] as const,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.page = 1
      void loadEvents()
    }, 300)
  },
)

onMounted(() => {
  void loadEvents()
})
</script>

<template>
  <section class="pj-page events-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('events.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('events.subtitle') }}</p>
      </div>
      <Button
        v-if="can('events.create')"
        icon="pi pi-plus"
        :label="t('events.new')"
        @click="router.push({ name: 'events.create' })"
      />
    </header>

    <div v-if="isEventsAdmin" class="events-toolbar">
      <AppSearchField
        v-model="filters.search"
        class="events-toolbar__search"
        :placeholder="t('events.searchPlaceholder')"
      />
      <Select
        v-model="filters.estado"
        :options="estadoFilterOptions"
        option-label="label"
        option-value="value"
        class="events-toolbar__select"
      />
      <Select
        v-model="filters.audiencia"
        :options="audienciaFilterOptions"
        option-label="label"
        option-value="value"
        class="events-toolbar__select"
      />
      <Select
        v-model="filters.fecha"
        :options="fechaFilterOptions"
        option-label="label"
        option-value="value"
        class="events-toolbar__select"
      />
      <div class="events-toolbar__views" role="group" :aria-label="t('events.viewMode')">
        <Button
          type="button"
          size="small"
          :outlined="viewMode !== 'tree'"
          icon="pi pi-sitemap"
          :label="t('events.viewTree')"
          @click="viewMode = 'tree'"
        />
        <Button
          type="button"
          size="small"
          :outlined="viewMode !== 'banner'"
          icon="pi pi-image"
          :label="t('events.viewBanner')"
          @click="viewMode = 'banner'"
        />
      </div>
    </div>

    <PageLoader v-if="loading && !events.length" :label="t('common.loading')" />

    <div v-else class="events-list" :class="{ 'events-list--banner': listView === 'banner' }">
      <p v-if="!visibleEvents.length" class="pj-muted events-empty">{{ t('events.empty') }}</p>

      <article
        v-for="event in visibleEvents"
        :key="event.id"
        class="event-card-wrap"
        :class="{ 'event-card-wrap--banner': listView === 'banner' }"
      >
        <div :class="listView === 'banner' ? 'event-banner-stack' : 'event-card'">
        <EventBannerCard
          v-if="listView === 'banner'"
          :name="event.name"
          :banner-url="resolveFileUrl(event.banner_url)"
          :logo-url="resolveFileUrl(event.image_url)"
          :status-label="estadoMeta(event).label"
          :status-css="estadoMeta(event).css"
          :audience-label="audienceSummary(event)"
          :dates-label="formatDateRange(event.starts_at, event.ends_at)"
          :place-label="event.lugar || t('events.wizard.previewPlace')"
          :description="event.descripcion"
          :cupo-label="cupoLabel(event)"
          :score-label="enrollmentLabel(event)"
          :cupo-caption="t('events.wizard.participants')"
          :score-caption="t('events.enrollmentValue')"
        >
          <template v-if="canChangeEstado()" #status>
            <EventEstadoSelect
              compact
              :model-value="event.estado || 'borrador'"
              :loading="updatingEstadoId === event.id"
              @update:model-value="changeEventEstado(event, $event)"
            />
          </template>
          <div class="event-card__side event-card__side--banner">
            <span
              v-if="inscripcionStatusMeta(event.inscripcion_estado)"
              class="inscripcion-chip"
              :class="inscripcionStatusMeta(event.inscripcion_estado)?.css"
            >
              {{ inscripcionStatusMeta(event.inscripcion_estado)?.label }}
            </span>
            <div
              v-if="canEnroll(event) || canParticipate(event) || canJudge(event) || canViewStandings(event) || canReviewInscripciones(event) || canAccessAlojamiento(event) || canCatalog('terrenos', 'view') || can('terrenos.assign')"
              class="event-card__actions"
            >
              <Button
                v-if="canEnroll(event)"
                type="button"
                size="small"
                icon="pi pi-user-plus"
                :label="enrollButtonLabel(event)"
                @click="goEnroll(event)"
              />
              <Button
                v-if="canReviewInscripciones(event)"
                type="button"
                size="small"
                severity="secondary"
                icon="pi pi-inbox"
                :label="t('events.revisionMenu')"
                @click="goInscripcionesRevision(event)"
              />
              <Button
                v-if="canParticipate(event)"
                type="button"
                size="small"
                severity="secondary"
                icon="pi pi-play"
                :label="t('events.participate')"
                @click="goParticipate(event)"
              />
              <Button
                v-if="canJudge(event)"
                type="button"
                size="small"
                severity="help"
                icon="pi pi-pencil"
                :label="t('events.judge')"
                @click="goJudge(event)"
              />
              <Button
                v-if="canViewStandings(event)"
                type="button"
                size="small"
                outlined
                icon="pi pi-chart-bar"
                :label="t('events.standings')"
                @click="goStandings(event)"
              />
              <Button
                v-if="canViewStandings(event)"
                type="button"
                size="small"
                outlined
                icon="pi pi-sitemap"
                :label="t('events.standingsTree')"
                @click="goStandingsTree(event)"
              />
              <span v-if="canAccessDistribucion(event)">
                <Button
                  type="button"
                  size="small"
                  outlined
                  icon="pi pi-map"
                  :label="distribucionButtonLabel()"
                  @click="router.push({ name: 'events.distribucion', params: { id: event.id } })"
                />
              </span>
              <Button
                v-if="canAccessAlojamiento(event)"
                type="button"
                size="small"
                outlined
                icon="pi pi-building"
                :label="event.alojamiento_asignado ? t('alojamiento.change') : t('alojamiento.action')"
                @click="router.push({ name: 'events.alojamiento', params: { id: event.id } })"
              />
            </div>
            <Button
              v-if="menuItemsFor(event).length"
              type="button"
              icon="pi pi-ellipsis-v"
              text
              rounded
              class="event-card__menu-btn"
              @click="toggleMenu(event.id, $event)"
            />
            <Menu
              :ref="(el) => setMenuRef(event.id, el)"
              :model="menuItemsFor(event)"
              popup
            />
          </div>
        </EventBannerCard>
          <div v-if="listView === 'tree'" class="event-card__media">
            <img v-if="event.image_url" :src="event.image_url" :alt="event.name" />
            <div v-else class="event-card__media-empty">
              <i class="pi pi-image" />
            </div>
          </div>

          <div v-if="listView === 'tree'" class="event-card__icon" :class="categoryTone(event)">
            <i :class="categoryIcon(event)" />
          </div>

          <div v-if="listView === 'tree'" class="event-card__body">
            <h2 class="event-card__title">{{ event.name }}</h2>
            <p v-if="event.lugar" class="event-card__meta">
              <i class="pi pi-map-marker" />
              <span>{{ event.lugar }}</span>
            </p>
            <p class="event-card__meta">
              <i class="pi pi-calendar" />
              <span>{{ formatDateRange(event.starts_at, event.ends_at) }}</span>
            </p>
            <div class="event-card__badges">
              <span
                v-if="event.tipo_evento"
                class="audience-badge badge--tipo"
                :style="event.tipo_evento.color ? { borderColor: cssColor(event.tipo_evento.color), color: cssColor(event.tipo_evento.color) } : undefined"
              >
                {{ event.tipo_evento.nombre }}
              </span>
              <span
                v-for="badge in audienceBadges(event)"
                :key="badge.key"
                class="audience-badge"
                :class="badge.css"
              >
                {{ badge.label }}
              </span>
              <button
                v-if="hasChildren(event)"
                type="button"
                class="audience-badge badge--children"
                @click="toggleRootChildren(event.id)"
              >
                <i :class="isExpanded(event.id) ? 'pi pi-chevron-down' : 'pi pi-chevron-right'" />
                {{ t('events.childrenCount', { count: childrenCount(event) }) }}
              </button>
              <span
                v-if="listMode === 'judge' && event.progreso_juez"
                class="audience-badge badge--progress"
              >
                {{ event.progreso_juez.calificados }} {{ t('events.listScoredShort') }}
                ·
                {{ event.progreso_juez.pendientes }} {{ t('events.listPendingShort') }}
              </span>
              <span
                v-else-if="listMode === 'director' && event.progreso_evidencia"
                class="audience-badge badge--progress"
              >
                {{ event.progreso_evidencia.con_evidencia }} {{ t('events.listWithEvidenceShort') }}
                ·
                {{ event.progreso_evidencia.sin_evidencia }} {{ t('events.listWithoutEvidenceShort') }}
              </span>
            </div>
          </div>

        <div v-if="listView === 'tree'" class="event-card__side">
            <EventEstadoSelect
              v-if="canChangeEstado()"
              :model-value="event.estado || 'borrador'"
              :loading="updatingEstadoId === event.id"
              @update:model-value="changeEventEstado(event, $event)"
            />
            <span v-else class="status-pill" :class="estadoMeta(event).css">
              {{ estadoMeta(event).label }}
            </span>
            <span
              v-if="inscripcionStatusMeta(event.inscripcion_estado)"
              class="inscripcion-chip"
              :class="inscripcionStatusMeta(event.inscripcion_estado)?.css"
            >
              {{ inscripcionStatusMeta(event.inscripcion_estado)?.label }}
            </span>
            <div
              v-if="canEnroll(event) || canParticipate(event) || canJudge(event) || canViewStandings(event) || canReviewInscripciones(event) || canAccessAlojamiento(event) || canCatalog('terrenos', 'view') || can('terrenos.assign')"
              class="event-card__actions"
            >
              <Button
                v-if="canEnroll(event)"
                type="button"
                size="small"
                icon="pi pi-user-plus"
                :label="enrollButtonLabel(event)"
                @click="goEnroll(event)"
              />
              <Button
                v-if="canReviewInscripciones(event)"
                type="button"
                size="small"
                severity="secondary"
                icon="pi pi-inbox"
                :label="t('events.revisionMenu')"
                @click="goInscripcionesRevision(event)"
              />
              <Button
                v-if="canParticipate(event)"
                type="button"
                size="small"
                severity="secondary"
                icon="pi pi-play"
                :label="t('events.participate')"
                @click="goParticipate(event)"
              />
              <Button
                v-if="canJudge(event)"
                type="button"
                size="small"
                severity="help"
                icon="pi pi-pencil"
                :label="t('events.judge')"
                @click="goJudge(event)"
              />
              <Button
                v-if="canViewStandings(event)"
                type="button"
                size="small"
                outlined
                icon="pi pi-chart-bar"
                :label="t('events.standings')"
                @click="goStandings(event)"
              />
              <Button
                v-if="canViewStandings(event)"
                type="button"
                size="small"
                outlined
                icon="pi pi-sitemap"
                :label="t('events.standingsTree')"
                @click="goStandingsTree(event)"
              />
              <span
                v-if="canAccessDistribucion(event)"
              >
                <Button
                  type="button"
                  size="small"
                  outlined
                  icon="pi pi-map"
                  :label="distribucionButtonLabel()"
                  @click="router.push({ name: 'events.distribucion', params: { id: event.id } })"
                />
              </span>
              <Button
                v-if="canAccessAlojamiento(event)"
                type="button"
                size="small"
                outlined
                icon="pi pi-building"
                :label="event.alojamiento_asignado ? t('alojamiento.change') : t('alojamiento.action')"
                @click="router.push({ name: 'events.alojamiento', params: { id: event.id } })"
              />
            </div>
            <Button
              v-if="menuItemsFor(event).length"
              type="button"
              icon="pi pi-ellipsis-v"
              text
              rounded
              class="event-card__menu-btn"
              @click="toggleMenu(event.id, $event)"
            />
            <Menu
              :ref="(el) => setMenuRef(event.id, el)"
              :model="menuItemsFor(event)"
              popup
            />
        </div>
        </div>

        <EventChildrenAccordion
          v-if="listView === 'tree' && hasChildren(event) && isExpanded(event.id) && event.hijos?.length"
          :nodes="event.hijos"
          :expanded="expandedChildren"
          :mode="listMode"
          :root-id="event.id"
          @toggle="toggleTreeNode"
          @open="onTreeNodeOpen"
        />
      </article>
    </div>

    <div v-if="!(loading && !events.length)" class="events-footer">
      <p class="pj-muted">{{ rangeLabel }}</p>
      <Paginator
        :rows="filters.per_page"
        :total-records="pagination?.total ?? events.length"
        :first="((pagination?.current_page ?? 1) - 1) * (pagination?.per_page ?? filters.per_page)"
        template="PrevPageLink PageLinks NextPageLink"
        @page="onPage"
      />
    </div>

    <Dialog
      v-model:visible="deleteDialogVisible"
      modal
      :header="t('common.confirm')"
      :style="{ width: '28rem' }"
    >
      <p>{{ t('events.deleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteTarget = null" />
        <Button
          :label="t('common.delete')"
          severity="danger"
          :loading="deleting"
          @click="confirmDelete"
        />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.events-page {
  flex: 1;
  min-height: calc(100dvh - 8.75rem);
}

.events-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: center;
  margin-bottom: 1rem;
  padding: 0.75rem 0.85rem;
  background: color-mix(in srgb, var(--pj-bg-elevated) 94%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-border) 70%, transparent);
  border-radius: 12px;
}

.events-toolbar__search {
  flex: 1 0 100%;
  min-width: 0;
}

.events-toolbar__select {
  min-width: 10.5rem;
}

.events-toolbar__views {
  display: flex;
  gap: 0.35rem;
  margin-left: auto;
}

.events-list--banner {
  display: flex;
  flex-direction: row;
  flex-wrap: wrap;
  justify-content: center;
  align-items: stretch;
  gap: 1.15rem;
  width: 100%;
}

.events-list--banner .events-empty {
  flex: 1 1 100%;
}

.event-card-wrap--banner {
  background: none;
  border: none;
  box-shadow: none;
  padding: 0;
  width: min(100%, 22.5rem);
  max-width: 22.5rem;
  flex: 0 1 22.5rem;
}

.event-banner-stack {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  min-width: 0;
}

.event-card__side--banner {
  flex-direction: row;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-start;
  align-self: stretch;
  width: 100%;
  padding: 0;
  gap: 0.45rem;
}

.event-card__side--banner .event-card__actions {
  justify-content: flex-start;
}

.events-list:not(.events-list--banner) {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.events-empty {
  padding: 2rem 1rem;
  text-align: center;
}

.event-card-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 0.4rem;
  border-radius: 18px;
  background: linear-gradient(180deg, color-mix(in srgb, #0f766e 5%, #fff), #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  box-shadow: 0 10px 28px -20px rgba(15, 23, 42, 0.4);
}

.event-card {
  display: grid;
  grid-template-columns: 7.5rem auto 1fr auto;
  gap: 0.85rem;
  align-items: center;
  padding: 0.85rem;
  background: color-mix(in srgb, var(--pj-bg-elevated) 96%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  border-radius: 14px;
}

.event-card__media {
  width: 7.5rem;
  height: 5.25rem;
  border-radius: 10px;
  overflow: hidden;
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
}

.event-card__media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.event-card__media-empty {
  height: 100%;
  display: grid;
  place-items: center;
  color: color-mix(in srgb, var(--pj-navy) 40%, transparent);
  font-size: 1.4rem;
}

.event-card__icon {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 999px;
  display: grid;
  place-items: center;
  color: #fff;
  flex-shrink: 0;
}

.tone--conquistadores { background: #ea580c; }
.tone--aventureros { background: #16a34a; }
.tone--guias { background: #7c3aed; }
.tone--default { background: #2563eb; }

.event-card__body {
  min-width: 0;
}

.event-card__title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--pj-navy);
}

.event-card__meta {
  margin: 0.15rem 0;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--pj-text-muted);
  font-size: 0.86rem;
}

.event-card__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-top: 0.55rem;
}

.audience-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
}

.badge--conquistadores {
  background: color-mix(in srgb, #ea580c 16%, transparent);
  color: #c2410c;
}

.badge--aventureros {
  background: color-mix(in srgb, #16a34a 16%, transparent);
  color: #15803d;
}

.badge--guias {
  background: color-mix(in srgb, #7c3aed 16%, transparent);
  color: #6d28d9;
}

.badge--all {
  background: color-mix(in srgb, #2563eb 12%, transparent);
  color: #1d4ed8;
  border: 1px solid color-mix(in srgb, #2563eb 35%, transparent);
}

.badge--tipo {
  background: color-mix(in srgb, var(--pj-navy) 6%, transparent);
}

.badge--children {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  cursor: pointer;
  background: color-mix(in srgb, var(--pj-navy) 8%, transparent);
  color: var(--pj-navy);
  border: 1px solid color-mix(in srgb, var(--pj-navy) 25%, transparent);
  font: inherit;
}

.badge--children:hover {
  background: color-mix(in srgb, var(--pj-navy) 14%, transparent);
}

.badge--progress {
  background: color-mix(in srgb, #0f766e 10%, transparent);
  color: #0f766e;
  border: 1px solid color-mix(in srgb, #0f766e 22%, transparent);
  font-variant-numeric: tabular-nums;
}

.badge--default {
  background: color-mix(in srgb, var(--pj-navy) 10%, transparent);
  color: var(--pj-navy);
}

.event-card__side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.55rem;
  align-self: stretch;
  justify-content: space-between;
}

.event-card__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.4rem;
}

.enrolled-chip {
  font-size: 0.75rem;
  color: var(--pj-text-muted);
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
}

.inscripcion-chip {
  font-size: 0.72rem;
  font-weight: 700;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
}

.insc--pending {
  background: color-mix(in srgb, #f59e0b 16%, transparent);
  color: #b45309;
}

.insc--review {
  background: color-mix(in srgb, #2563eb 14%, transparent);
  color: #1d4ed8;
}

.insc--approved {
  background: color-mix(in srgb, #16a34a 16%, transparent);
  color: #15803d;
}

.insc--rejected {
  background: color-mix(in srgb, #dc2626 14%, transparent);
  color: #b91c1c;
}

.insc--default {
  background: color-mix(in srgb, var(--pj-navy) 10%, transparent);
  color: var(--pj-navy);
}

.status-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 700;
}

.status--publicado {
  background: color-mix(in srgb, #16a34a 16%, transparent);
  color: #15803d;
}

.status--en-proceso {
  background: color-mix(in srgb, #2563eb 16%, transparent);
  color: #1d4ed8;
}

.status--borrador {
  background: color-mix(in srgb, #f59e0b 18%, transparent);
  color: #b45309;
}

.status--cerrado,
.status--cancelado,
.status--default {
  background: color-mix(in srgb, #64748b 16%, transparent);
  color: #475569;
}

.events-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  margin-top: auto;
  position: sticky;
  bottom: 0;
  z-index: 4;
  padding: 0.45rem 0 0.15rem;
  background: color-mix(in srgb, var(--pj-bg) 92%, transparent);
  backdrop-filter: blur(8px);
}

@media (max-width: 860px) {
  .event-card {
    grid-template-columns: 5.5rem 1fr auto;
  }

  .event-card__icon {
    display: none;
  }
}

@media (max-width: 560px) {
  .event-card {
    grid-template-columns: 1fr;
  }

  .event-card__media {
    width: 100%;
    height: 8rem;
  }

  .event-card__side {
    flex-direction: row;
    justify-content: space-between;
    width: 100%;
  }
}
</style>
