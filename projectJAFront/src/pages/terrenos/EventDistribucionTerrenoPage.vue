<script setup lang="ts">
import { computed, nextTick, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useMediaQuery } from '@vueuse/core'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Tag from 'primevue/tag'
import Drawer from 'primevue/drawer'
import Dialog from 'primevue/dialog'
import PageLoader from '@/components/PageLoader.vue'
import TerrenoMapCanvas from '@/components/terrenos/TerrenoMapCanvas.vue'
import AsignarClubLoteDialog from '@/components/terrenos/AsignarClubLoteDialog.vue'
import type { MapFeature } from '@/components/terrenos/TerrenoMapCanvas.vue'
import { terrenosService } from '@/services/terrenosService'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { useRealtimeChannel } from '@/composables/useRealtimeChannel'
import { useAuthStore } from '@/stores/auth'
import type { ClubEvent } from '@/modules/events/types'
import type {
  ConfiguracionTerreno,
  EventoLote,
  EventoTerreno,
  EventoZona,
  MapLayerMode,
  Terreno,
} from '@/modules/terrenos/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()
const auth = useAuthStore()

const eventId = computed(() => Number(route.params.id))
const event = ref<ClubEvent | null>(null)
const distribucion = ref<EventoTerreno | null>(null)
const terrenos = ref<Terreno[]>([])
const configs = ref<ConfiguracionTerreno[]>([])
const selectedTerrenoId = ref<number | null>(null)
const selectedConfigId = ref<number | null>(null)
const loading = ref(true)
const attaching = ref(false)
const saving = ref(false)
const layer = ref<MapLayerMode>('satellite')
const selectedLote = ref<EventoLote | null>(null)
const selectedZona = ref<EventoZona | null>(null)
const assignVisible = ref(false)
const detachVisible = ref(false)
const drawerOpen = ref(false)
const isMobile = useMediaQuery('(max-width: 900px)')
const lotesPanelOpen = ref(false)
const mapRef = ref<InstanceType<typeof TerrenoMapCanvas> | null>(null)
const loteMenu = ref<{ x: number; y: number; lote: EventoLote } | null>(null)
let loteMenuCloser: ((e: MouseEvent) => void) | null = null
let distributionPollTimer: number | null = null
let realtimeRefreshRunning = false
let realtimeRefreshPending = false

const canAssign = computed(() => can('terrenos.assign') || can('terrenos.update'))
const canOverride = computed(() => can('terrenos.override_capacity'))
const isDirectorContext = computed(() => {
  const role = (auth.contexto?.rol_name || '').toLowerCase()
  return Boolean(auth.contexto?.organizacion_id) && (role === 'director' || role === 'subdirector')
})
const canSelfAssign = computed(() => isDirectorContext.value && !canAssign.value)

watch(lotesPanelOpen, async () => {
  await nextTick()
  // El ancho del mapa cambia al mostrar/ocultar el panel: reencuadrar al terreno
  window.setTimeout(() => mapRef.value?.lockAndFit?.(), 80)
})

const layerOptions = computed(() => [
  { label: t('terrenos.layer.map'), value: 'roadmap' as MapLayerMode },
  { label: t('terrenos.layer.satellite'), value: 'satellite' as MapLayerMode },
  { label: t('terrenos.layer.image'), value: 'imagen' as MapLayerMode },
])

function clubInitials(name?: string | null): string {
  if (!name) return 'CL'
  return name
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word.charAt(0).toUpperCase())
    .join('')
}

const features = computed<MapFeature[]>(() => {
  if (!distribucion.value) return []
  const list: MapFeature[] = []
  if (distribucion.value.terreno?.geometria) {
    list.push({
      key: `terreno-${distribucion.value.terreno_id}`,
      kind: 'terreno',
      id: distribucion.value.terreno_id,
      label: distribucion.value.terreno.nombre,
      geometria: distribucion.value.terreno.geometria,
    })
  }
  for (const zona of distribucion.value.zonas || []) {
    list.push({
      key: `zona-${zona.id}`,
      kind: 'zona',
      id: zona.id,
      label: zona.nombre,
      geometria: zona.geometria,
      color: zona.color,
    })
    for (const lote of zona.lotes || []) {
      list.push({
        key: `lote-${lote.id}`,
        kind: 'lote',
        id: lote.id,
        label: lote.codigo,
        geometria: lote.geometria,
        estado: lote.estado,
        parentId: zona.id,
        logoUrl: lote.asignacion?.club?.logo,
        clubName: lote.asignacion?.club?.nombre,
        clubInitials: lote.asignacion?.club
          ? clubInitials(lote.asignacion.club.nombre)
          : null,
      })
    }
  }
  for (const lote of distribucion.value.lotes || []) {
    list.push({
      key: `lote-${lote.id}`,
      kind: 'lote',
      id: lote.id,
      label: lote.codigo,
      geometria: lote.geometria,
      estado: lote.estado,
      parentId: null,
      logoUrl: lote.asignacion?.club?.logo,
      clubName: lote.asignacion?.club?.nombre,
      clubInitials: lote.asignacion?.club
        ? clubInitials(lote.asignacion.club.nombre)
        : null,
    })
  }
  for (const estructura of distribucion.value.estructuras || []) {
    list.push({
      key: `estructura-${estructura.id}`,
      kind: 'estructura',
      id: estructura.id,
      label: estructura.nombre,
      geometria: estructura.geometria,
      color: estructura.color,
    })
  }
  return list
})

const selectedKey = computed(() => (selectedLote.value ? `lote-${selectedLote.value.id}` : null))

const allLotes = computed(() => {
  const rows: Array<{ zonaNombre: string; lote: EventoLote }> = []
  for (const zona of distribucion.value?.zonas || []) {
    for (const lote of zona.lotes || []) {
      rows.push({ zonaNombre: zona.nombre, lote })
    }
  }
  for (const lote of distribucion.value?.lotes || []) {
    rows.push({ zonaNombre: t('terrenos.lotesDirectos'), lote })
  }
  return rows
})

const ownAssignment = computed(() => {
  const organizationId = auth.contexto?.organizacion_id
  if (!organizationId) return null
  return allLotes.value.find(
    ({ lote }) => lote.asignacion?.club?.organizacion_id === organizationId,
  )?.lote ?? null
})

function canChooseLote(lote: EventoLote): boolean {
  return canSelfAssign.value
    && !ownAssignment.value
    && lote.estado === 'disponible'
    && !lote.asignacion
}

function findLoteById(id: number): EventoLote | null {
  const direct = (distribucion.value?.lotes || []).find((l) => l.id === id)
  if (direct) return direct
  for (const zona of distribucion.value?.zonas || []) {
    const lote = (zona.lotes || []).find((l) => l.id === id)
    if (lote) return lote
  }
  return null
}

function closeLoteMenu(): void {
  loteMenu.value = null
  if (loteMenuCloser) {
    window.removeEventListener('mousedown', loteMenuCloser, true)
    loteMenuCloser = null
  }
}

function openLoteMenu(lote: EventoLote, x: number, y: number): void {
  closeLoteMenu()
  loteMenu.value = { lote, x, y }
  loteMenuCloser = (e: MouseEvent) => {
    const target = e.target as HTMLElement | null
    if (target?.closest('.lote-ctx-menu')) return
    closeLoteMenu()
  }
  window.addEventListener('mousedown', loteMenuCloser, true)
}

async function loadConfigs(terrenoId: number | null): Promise<void> {
  selectedConfigId.value = null
  configs.value = []
  if (!terrenoId) return
  try {
    configs.value = await terrenosService.listConfigs(terrenoId)
    const defaultCfg = configs.value.find((c) => c.es_default) || configs.value[0]
    if (defaultCfg) selectedConfigId.value = defaultCfg.id
  } catch {
    configs.value = []
  }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const [ev, dist, list] = await Promise.all([
      eventsService.get(eventId.value),
      terrenosService.getDistribucion(eventId.value),
      canAssign.value
        ? terrenosService.list({ per_page: 100 })
        : Promise.resolve({ items: [] as Terreno[], pagination: null }),
    ])
    event.value = ev
    distribucion.value = dist
    terrenos.value = list.items
    if (dist) {
      selectedTerrenoId.value = dist.terreno_id
      selectedConfigId.value = dist.configuracion_terreno_id ?? null
      lotesPanelOpen.value = canSelfAssign.value && !isMobile.value
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

async function refreshDistribucion(): Promise<void> {
  distribucion.value = await terrenosService.getDistribucion(eventId.value)
  if (selectedLote.value) {
    selectedLote.value = findLoteById(selectedLote.value.id)
  }
}

async function syncDistributionSilently(): Promise<void> {
  if (loading.value || realtimeRefreshRunning) {
    realtimeRefreshPending = true
    return
  }
  realtimeRefreshPending = false
  realtimeRefreshRunning = true
  try {
    await refreshDistribucion()
  } catch {
    // La siguiente notificación o sondeo volverá a intentar la sincronización.
  } finally {
    realtimeRefreshRunning = false
    if (realtimeRefreshPending) {
      realtimeRefreshPending = false
      void syncDistributionSilently()
    }
  }
}

useRealtimeChannel(
  () => eventId.value ? `events.${eventId.value}.distribution` : null,
  {
    '.distribution.changed': (raw) => {
      const payload = raw as { evento_id?: number }
      if (Number(payload?.evento_id) !== eventId.value) return
      void syncDistributionSilently()
    },
  },
)

async function attach(): Promise<void> {
  if (!selectedTerrenoId.value || !selectedConfigId.value) return
  attaching.value = true
  try {
    distribucion.value = await terrenosService.attachTerreno(
      eventId.value,
      selectedTerrenoId.value,
      selectedConfigId.value,
    )
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.attachSuccess'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    attaching.value = false
  }
}

async function detach(): Promise<void> {
  attaching.value = true
  try {
    await terrenosService.detachTerreno(eventId.value)
    distribucion.value = null
    selectedLote.value = null
    selectedConfigId.value = null
    configs.value = []
    detachVisible.value = false
    closeLoteMenu()
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.detachSuccess'), life: 2500 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    attaching.value = false
  }
}

watch(selectedTerrenoId, (id) => {
  if (distribucion.value) return
  void loadConfigs(id)
})

function onSelectFeature(f: MapFeature): void {
  if (f.kind !== 'lote') return
  const direct = (distribucion.value?.lotes || []).find((l) => l.id === f.id)
  if (direct) {
    selectedZona.value = null
    selectedLote.value = direct
    if (isMobile.value) drawerOpen.value = true
    return
  }
  for (const zona of distribucion.value?.zonas || []) {
    const lote = (zona.lotes || []).find((l) => l.id === f.id)
    if (lote) {
      selectedZona.value = zona
      selectedLote.value = lote
      if (isMobile.value) drawerOpen.value = true
      break
    }
  }
}

function onLoteContextMenu(payload: { feature: MapFeature; x: number; y: number }): void {
  if ((!canAssign.value && !canSelfAssign.value) || payload.feature.kind !== 'lote') return
  const lote = findLoteById(payload.feature.id)
  if (!lote) return
  selectedLote.value = lote
  openLoteMenu(lote, payload.x, payload.y)
}

function openAssign(lote: EventoLote): void {
  closeLoteMenu()
  if (canSelfAssign.value && !canChooseLote(lote)) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: ownAssignment.value
        ? t('terrenos.selfAssignAlreadySelected')
        : t('terrenos.selfAssignUnavailable'),
      life: 4000,
    })
    return
  }
  if (lote.estado === 'no_disponible' && !lote.asignacion) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('terrenos.loteNoDisponibleBlock'),
      life: 4000,
    })
    return
  }
  selectedLote.value = lote
  assignVisible.value = true
}

async function setLoteEstado(
  lote: EventoLote,
  estado: 'disponible' | 'reservado' | 'no_disponible',
): Promise<void> {
  closeLoteMenu()
  if (lote.asignacion?.estado === 'activa') {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('terrenos.loteHasAsignacionBlock'),
      life: 4000,
    })
    return
  }
  saving.value = true
  try {
    await terrenosService.updateEventoLote(lote.id, { estado })
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.loteEstadoUpdated'), life: 2200 })
    await refreshDistribucion()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function submitAssign(payload: {
  club_id?: number
  cantidad_personas: number
  observaciones?: string
}): Promise<void> {
  if (!selectedLote.value) return
  saving.value = true
  try {
    if (canSelfAssign.value) {
      await terrenosService.selfAssignLote(selectedLote.value.id, payload.observaciones)
    } else if (selectedLote.value.asignacion?.estado === 'activa') {
      if (!payload.club_id) return
      await terrenosService.updateAsignacion(selectedLote.value.asignacion.id, payload)
    } else {
      if (!payload.club_id) return
      await terrenosService.assignLote(selectedLote.value.id, payload)
    }
    assignVisible.value = false
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: canSelfAssign.value ? t('terrenos.selfAssignSuccess') : t('terrenos.assignSuccess'),
      life: 2500,
    })
    await refreshDistribucion()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function liberar(lote: EventoLote): Promise<void> {
  if (!lote.asignacion) return
  closeLoteMenu()
  saving.value = true
  try {
    await terrenosService.liberarAsignacion(lote.asignacion.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.releaseSuccess'), life: 2500 })
    await refreshDistribucion()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

function estadoSeverity(estado: string): 'success' | 'info' | 'warn' | 'danger' | 'secondary' {
  if (estado === 'disponible') return 'success'
  if (estado === 'asignado') return 'info'
  if (estado === 'reservado') return 'warn'
  if (estado === 'no_disponible') return 'danger'
  return 'secondary'
}

function estadoLabel(estado: string): string {
  const key = `terrenos.estado.${estado}`
  const translated = t(key)
  return translated === key ? estado : translated
}

onMounted(() => {
  void load()
  distributionPollTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') {
      void syncDistributionSilently()
    }
  }, 3000)
})
onBeforeUnmount(() => {
  closeLoteMenu()
  if (distributionPollTimer !== null) {
    window.clearInterval(distributionPollTimer)
    distributionPollTimer = null
  }
})
</script>

<template>
  <section class="distribucion-page">
    <header class="distribucion-page__toolbar">
      <Button icon="pi pi-arrow-left" text rounded @click="router.push({ name: 'events' })" />
      <div class="title">
        <h1 class="pj-display">{{ t('terrenos.distribucionTitle') }}</h1>
        <p>{{ event?.nombre || '—' }}</p>
        <div v-if="distribucion" class="legend toolbar-legend">
          <span><i class="dot disponible" /> {{ t('terrenos.estado.disponible') }}</span>
          <span><i class="dot asignado" /> {{ t('terrenos.estado.asignado') }}</span>
          <span><i class="dot reservado" /> {{ t('terrenos.estado.reservado') }}</span>
          <span><i class="dot no_disponible" /> {{ t('terrenos.estado.no_disponible') }}</span>
        </div>
      </div>
      <SelectButton
        v-if="distribucion"
        v-model="layer"
        :options="layerOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
      />
      <Button
        v-if="distribucion && !isMobile"
        :label="lotesPanelOpen ? t('terrenos.hideLotesList') : t('terrenos.showLotesList')"
        :icon="lotesPanelOpen ? 'pi pi-eye-slash' : 'pi pi-list'"
        size="small"
        severity="secondary"
        outlined
        @click="lotesPanelOpen = !lotesPanelOpen"
      />
      <Button
        v-if="isMobile && distribucion"
        icon="pi pi-list"
        text
        rounded
        @click="drawerOpen = true"
      />
    </header>

    <PageLoader v-if="loading" />

    <div v-else-if="!distribucion" class="attach-card">
      <template v-if="canAssign">
        <h2>{{ t('terrenos.attachTerreno') }}</h2>
        <p>{{ t('terrenos.attachHint') }}</p>
      </template>
      <template v-else>
        <h2>{{ t('terrenos.distribucionUnavailable') }}</h2>
        <p>{{ t('terrenos.distribucionUnavailableHint') }}</p>
      </template>
      <div v-if="canAssign" class="attach-row">
        <Select
          v-model="selectedTerrenoId"
          :options="terrenos"
          option-label="nombre"
          option-value="id"
          :placeholder="t('terrenos.selectTerreno')"
          filter
          class="grow"
        />
        <Select
          v-model="selectedConfigId"
          :options="configs"
          option-label="nombre"
          option-value="id"
          :placeholder="t('terrenos.selectConfig')"
          filter
          class="grow"
          :disabled="!selectedTerrenoId || !configs.length"
        />
        <Button
          v-if="canAssign"
          :label="t('terrenos.attach')"
          icon="pi pi-link"
          :loading="attaching"
          :disabled="!selectedTerrenoId || !selectedConfigId"
          @click="attach"
        />
      </div>
      <p v-if="selectedTerrenoId && !configs.length" class="muted">{{ t('terrenos.noConfigs') }}</p>
    </div>

    <div v-else-if="ownAssignment" class="self-assignment-banner">
      <i class="pi pi-map-marker" />
      <div>
        <strong>{{ t('terrenos.selfAssignCurrent', { code: ownAssignment.codigo }) }}</strong>
        <span>{{ t('terrenos.selfAssignCurrentHint') }}</span>
      </div>
    </div>

    <div v-if="distribucion" class="distribucion-page__body" :class="{ 'is-panel-hidden': !lotesPanelOpen || isMobile }">
      <div class="map-wrap">
        <TerrenoMapCanvas
          ref="mapRef"
          :features="features"
          :center="
            distribucion.terreno?.latitud && distribucion.terreno?.longitud
              ? { lat: distribucion.terreno.latitud, lng: distribucion.terreno.longitud }
              : null
          "
          :zoom="distribucion.terreno?.nivel_zoom || 12"
          tool="select"
          :layer="layer"
          :imagen-url="distribucion.terreno?.imagen_referencia"
          :selected-key="selectedKey"
          lock-viewport
          :enable-lote-actions-menu="canAssign || canSelfAssign"
          @select="onSelectFeature"
          @lote-context-menu="onLoteContextMenu"
        />
        <div
          v-if="loteMenu"
          class="lote-ctx-menu"
          :style="{ left: `${loteMenu.x}px`, top: `${loteMenu.y}px` }"
          @mousedown.stop
        >
          <header class="lote-ctx-menu__head">{{ loteMenu.lote.codigo }}</header>
          <button
            v-if="canSelfAssign"
            type="button"
            :disabled="saving || !canChooseLote(loteMenu.lote)"
            @click="openAssign(loteMenu.lote)"
          >
            {{ t('terrenos.elegirEsteLote') }}
          </button>
          <template v-if="canAssign">
            <button type="button" :disabled="saving" @click="openAssign(loteMenu.lote)">
            {{
              loteMenu.lote.asignacion
                ? t('terrenos.loteContextEditAssign')
                : t('terrenos.loteContextAssign')
            }}
            </button>
            <button
              v-if="loteMenu.lote.asignacion"
              type="button"
              :disabled="saving"
              @click="liberar(loteMenu.lote)"
            >
              {{ t('terrenos.loteContextLiberar') }}
            </button>
            <hr v-if="!loteMenu.lote.asignacion" />
            <button
              v-if="!loteMenu.lote.asignacion"
              type="button"
              :disabled="saving || loteMenu.lote.estado === 'disponible'"
              @click="setLoteEstado(loteMenu.lote, 'disponible')"
            >
              {{ t('terrenos.loteContextDisponible') }}
            </button>
            <button
              v-if="!loteMenu.lote.asignacion"
              type="button"
              :disabled="saving || loteMenu.lote.estado === 'reservado'"
              @click="setLoteEstado(loteMenu.lote, 'reservado')"
            >
              {{ t('terrenos.loteContextReservado') }}
            </button>
            <button
              v-if="!loteMenu.lote.asignacion"
              type="button"
              class="is-danger"
              :disabled="saving || loteMenu.lote.estado === 'no_disponible'"
              @click="setLoteEstado(loteMenu.lote, 'no_disponible')"
            >
              {{ t('terrenos.loteContextNoDisponible') }}
            </button>
          </template>
        </div>
      </div>

      <aside v-if="!isMobile && lotesPanelOpen" class="panel-wrap">
        <div class="panel">
          <header class="panel__head">
            <div>
              <h2>{{ distribucion.terreno?.nombre }}</h2>
              <p class="muted">{{ t('terrenos.snapshotHint') }}</p>
            </div>
            <div class="panel__head-actions">
              <Button
                icon="pi pi-eye-slash"
                text
                rounded
                size="small"
                v-tooltip.top="t('terrenos.hideLotesList')"
                @click="lotesPanelOpen = false"
              />
              <Button
                v-if="canAssign"
                :label="t('terrenos.detach')"
                severity="danger"
                text
                size="small"
                @click="detachVisible = true"
              />
            </div>
          </header>

          <div class="lotes-list">
            <article
              v-for="row in allLotes"
              :key="row.lote.id"
              class="lote-card"
              :class="{ 'is-selected': selectedLote?.id === row.lote.id }"
              @click="selectedLote = row.lote"
            >
              <div>
                <strong>{{ row.lote.codigo }}</strong>
                <small>{{ row.zonaNombre }}</small>
              </div>
              <Tag :value="estadoLabel(row.lote.estado)" :severity="estadoSeverity(row.lote.estado)" />
              <div v-if="row.lote.asignacion?.club" class="assigned-club">
                <span class="assigned-club__logo">
                  <img
                    v-if="row.lote.asignacion.club.logo"
                    :src="row.lote.asignacion.club.logo"
                    :alt="row.lote.asignacion.club.nombre"
                  />
                  <strong v-else>{{ clubInitials(row.lote.asignacion.club.nombre) }}</strong>
                </span>
                <span>
                  <strong>{{ row.lote.asignacion.club.nombre }}</strong>
                  <small>{{ row.lote.asignacion.cantidad_personas }} pax</small>
                </span>
              </div>
              <p v-else class="muted">
                {{ t('terrenos.capacidadMaxima') }}: {{ row.lote.capacidad_maxima ?? '—' }}
              </p>
              <div v-if="canAssign" class="lote-actions">
                <Button
                  size="small"
                  :label="row.lote.asignacion ? t('terrenos.editarAsignacion') : t('terrenos.asignar')"
                  @click.stop="openAssign(row.lote)"
                />
                <Button
                  v-if="row.lote.asignacion"
                  size="small"
                  text
                  severity="secondary"
                  :label="t('terrenos.liberar')"
                  :loading="saving"
                  @click.stop="liberar(row.lote)"
                />
                <Button
                  v-if="!row.lote.asignacion && row.lote.estado !== 'reservado'"
                  size="small"
                  text
                  severity="warn"
                  :label="t('terrenos.estado.reservado')"
                  :loading="saving"
                  @click.stop="setLoteEstado(row.lote, 'reservado')"
                />
                <Button
                  v-if="!row.lote.asignacion && row.lote.estado !== 'no_disponible'"
                  size="small"
                  text
                  severity="danger"
                  :label="t('terrenos.estado.no_disponible')"
                  :loading="saving"
                  @click.stop="setLoteEstado(row.lote, 'no_disponible')"
                />
                <Button
                  v-if="!row.lote.asignacion && row.lote.estado !== 'disponible'"
                  size="small"
                  text
                  severity="success"
                  :label="t('terrenos.estado.disponible')"
                  :loading="saving"
                  @click.stop="setLoteEstado(row.lote, 'disponible')"
                />
              </div>
              <div v-else-if="canChooseLote(row.lote)" class="lote-actions">
                <Button
                  size="small"
                  icon="pi pi-map-marker"
                  :label="t('terrenos.elegirEsteLote')"
                  @click.stop="openAssign(row.lote)"
                />
              </div>
            </article>
          </div>
        </div>
      </aside>
    </div>

    <Drawer v-model:visible="drawerOpen" position="bottom" :style="{ height: '70vh' }">
      <div class="panel">
        <div class="lotes-list">
          <article v-for="row in allLotes" :key="row.lote.id" class="lote-card">
            <div>
              <strong>{{ row.lote.codigo }}</strong>
              <Tag :value="estadoLabel(row.lote.estado)" :severity="estadoSeverity(row.lote.estado)" />
            </div>
            <div v-if="row.lote.asignacion?.club" class="assigned-club">
              <span class="assigned-club__logo">
                <img
                  v-if="row.lote.asignacion.club.logo"
                  :src="row.lote.asignacion.club.logo"
                  :alt="row.lote.asignacion.club.nombre"
                />
                <strong v-else>{{ clubInitials(row.lote.asignacion.club.nombre) }}</strong>
              </span>
              <span>
                <strong>{{ row.lote.asignacion.club.nombre }}</strong>
                <small>{{ row.lote.asignacion.cantidad_personas }} pax</small>
              </span>
            </div>
            <div v-if="canAssign" class="lote-actions">
              <Button size="small" :label="t('terrenos.asignar')" @click="openAssign(row.lote)" />
              <Button
                v-if="row.lote.asignacion"
                size="small"
                text
                :label="t('terrenos.liberar')"
                @click="liberar(row.lote)"
              />
              <Button
                v-if="!row.lote.asignacion && row.lote.estado !== 'reservado'"
                size="small"
                text
                severity="warn"
                :label="t('terrenos.estado.reservado')"
                @click="setLoteEstado(row.lote, 'reservado')"
              />
              <Button
                v-if="!row.lote.asignacion && row.lote.estado !== 'no_disponible'"
                size="small"
                text
                severity="danger"
                :label="t('terrenos.estado.no_disponible')"
                @click="setLoteEstado(row.lote, 'no_disponible')"
              />
            </div>
            <div v-else-if="canChooseLote(row.lote)" class="lote-actions">
              <Button
                size="small"
                icon="pi pi-map-marker"
                :label="t('terrenos.elegirEsteLote')"
                @click="openAssign(row.lote)"
              />
            </div>
          </article>
        </div>
      </div>
    </Drawer>

    <AsignarClubLoteDialog
      v-model:visible="assignVisible"
      :lote="selectedLote"
      :can-override="canOverride"
      :self-assign="canSelfAssign"
      :saving="saving"
      @submit="submitAssign"
    />

    <Dialog
      v-model:visible="detachVisible"
      modal
      :header="t('common.confirm')"
      :style="{ width: 'min(400px, 95vw)' }"
    >
      <p>{{ t('terrenos.detachConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="detachVisible = false" />
        <Button :label="t('terrenos.detach')" severity="danger" :loading="attaching" @click="detach" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.distribucion-page {
  --lote-disponible: #2e7d32;
  --lote-asignado: #1565c0;
  --lote-reservado: #f9a825;
  --lote-no-disponible: #c62828;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: calc(100vh - 6rem);
}

.distribucion-page__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.title {
  flex: 1;
}

.title h1,
.title p {
  margin: 0;
}

.title h1 {
  font-size: 1.2rem;
}

.title p {
  color: var(--pj-muted, #667);
  font-size: 0.9rem;
}

.self-assignment-banner {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  padding: 0.7rem 0.85rem;
  border: 1px solid color-mix(in srgb, var(--p-primary-color) 25%, transparent);
  border-radius: 10px;
  background: color-mix(in srgb, var(--p-primary-color) 7%, transparent);
  color: var(--p-primary-color);
}

.self-assignment-banner > div {
  display: flex;
  flex-direction: column;
}

.self-assignment-banner span {
  color: var(--pj-text-muted);
  font-size: 0.8rem;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem 0.9rem;
  font-size: 0.8rem;
  color: var(--pj-text, #1a1a1a);
}

.attach-card {
  padding: 1.5rem;
  border-radius: 12px;
  background: var(--pj-surface, #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 70%, transparent);
}

.attach-row {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-top: 1rem;
}

.grow {
  flex: 1;
  min-width: 220px;
}

.distribucion-page__body {
  display: grid;
  grid-template-columns: 1fr 360px;
  gap: 0.75rem;
  flex: 1;
  min-height: 480px;
}

.distribucion-page__body.is-panel-hidden {
  grid-template-columns: 1fr;
}

.map-wrap,
.panel-wrap {
  position: relative;
  min-height: 480px;
  border-radius: 12px;
  overflow: hidden;
  background: var(--pj-surface, #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 70%, transparent);
}

.toolbar-legend {
  margin-top: 0.35rem;
}

.panel__head-actions {
  display: flex;
  align-items: center;
  gap: 0.15rem;
}

.lote-ctx-menu {
  position: absolute;
  z-index: 6;
  min-width: 200px;
  padding: 0.3rem;
  border-radius: 8px;
  background: var(--pj-surface, #fff);
  box-shadow: 0 6px 20px rgb(0 0 0 / 18%);
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 80%, transparent);
}

.lote-ctx-menu__head {
  padding: 0.4rem 0.65rem 0.25rem;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--pj-muted, #667);
}

.lote-ctx-menu button {
  display: block;
  width: 100%;
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0.5rem 0.65rem;
  border-radius: 6px;
  cursor: pointer;
  font: inherit;
  color: var(--pj-text, #1a1a1a);
}

.lote-ctx-menu button:hover:not(:disabled) {
  background: color-mix(in srgb, var(--pj-border, #eee) 70%, transparent);
}

.lote-ctx-menu button:disabled {
  opacity: 0.45;
  cursor: default;
}

.lote-ctx-menu button.is-danger {
  color: #c62828;
}

.lote-ctx-menu hr {
  border: 0;
  border-top: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 80%, transparent);
  margin: 0.25rem 0;
}

.panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  height: 100%;
  overflow: auto;
}

.panel__head {
  display: flex;
  justify-content: space-between;
  gap: 0.5rem;
}

.panel__head h2 {
  margin: 0;
  font-size: 1.1rem;
}

.muted {
  color: var(--pj-muted, #667);
  margin: 0.15rem 0 0;
  font-size: 0.85rem;
}

.dot {
  display: inline-block;
  width: 0.65rem;
  height: 0.65rem;
  border-radius: 50%;
  margin-right: 0.25rem;
}

.dot.disponible { background: var(--lote-disponible); }
.dot.asignado { background: var(--lote-asignado); }
.dot.reservado { background: var(--lote-reservado); }
.dot.no_disponible { background: var(--lote-no-disponible); }

.lotes-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.lote-card {
  padding: 0.65rem 0.75rem;
  border-radius: 8px;
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 80%, transparent);
  cursor: pointer;
}

.lote-card.is-selected {
  border-color: #1565c0;
  box-shadow: inset 0 0 0 1px #1565c0;
}

.lote-card strong {
  margin-right: 0.5rem;
}

.lote-card p {
  margin: 0.35rem 0;
  font-size: 0.85rem;
}

.assigned-club {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  margin: 0.45rem 0;
}

.assigned-club__logo {
  display: grid;
  flex: 0 0 2.25rem;
  width: 2.25rem;
  height: 2.25rem;
  overflow: hidden;
  place-items: center;
  border: 1px solid var(--pj-border);
  border-radius: 50%;
  background: var(--pj-surface);
  color: var(--pj-text-muted);
}

.assigned-club__logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.assigned-club__logo > strong {
  margin: 0;
  color: var(--p-primary-color);
  font-size: 0.75rem;
  letter-spacing: 0.02em;
}

.assigned-club > span:last-child {
  display: flex;
  min-width: 0;
  flex-direction: column;
}

.assigned-club > span:last-child strong {
  overflow: hidden;
  margin: 0;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.assigned-club small {
  color: var(--pj-text-muted);
  font-size: 0.72rem;
}

.lote-actions {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
}

@media (max-width: 900px) {
  .distribucion-page__body {
    grid-template-columns: 1fr;
  }
}
</style>
