<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useMediaQuery } from '@vueuse/core'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import PageLoader from '@/components/PageLoader.vue'
import TerrenoMapCanvas from '@/components/terrenos/TerrenoMapCanvas.vue'
import TerrenoSidePanel from '@/components/terrenos/TerrenoSidePanel.vue'
import type { MapFeature } from '@/components/terrenos/TerrenoMapCanvas.vue'
import { terrenosService } from '@/services/terrenosService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { DEFAULT_MAP_ZOOM, isPathContainedInGeo, measurePaths, pathIntersectsGeo } from '@/modules/terrenos/geometria'
import type {
  ConfiguracionTerreno,
  GeoJsonGeometry,
  LoteTerreno,
  MapLayerMode,
  MapToolMode,
  Terreno,
  ZonaTerreno,
} from '@/modules/terrenos/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const terrenoId = computed(() => Number(route.params.id))
const configId = computed(() => Number(route.params.configId))
const config = ref<ConfiguracionTerreno | null>(null)
const loading = ref(true)
const saving = ref(false)
const tool = ref<MapToolMode>('select')
const layer = ref<MapLayerMode>('roadmap')
const selectedKind = ref<'terreno' | 'zona' | 'lote' | 'estructura' | null>('zona')
const selectedZona = ref<ZonaTerreno | null>(null)
const selectedLote = ref<LoteTerreno | null>(null)
const drawerOpen = ref(false)
const isMobile = useMediaQuery('(max-width: 900px)')

const loteDialogVisible = ref(false)
/** Tras confirmar/cancelar el diálogo, volver a dibujar lotes. */
const continueDrawLote = ref(false)
const loteForm = ref({
  codigo: '',
  nombre: '',
})
const pendingLote = ref<{
  geometria: GeoJsonGeometry
  area: number
  perimetro: number
  zona: ZonaTerreno | null
} | null>(null)

const canEdit = computed(() => can('terrenos.update'))

const editableKey = computed(() => {
  if (!canEdit.value || tool.value !== 'select') return null
  if (selectedKind.value === 'lote' && selectedLote.value?.geometria) {
    return `lote-${selectedLote.value.id}`
  }
  if (selectedKind.value === 'zona' && selectedZona.value?.geometria) {
    return `zona-${selectedZona.value.id}`
  }
  return null
})

function setTool(mode: MapToolMode): void {
  continueDrawLote.value = mode === 'draw_lote'
  tool.value = mode
}

function suggestNextCodigo(): string {
  const used = new Set<string>()
  for (const z of config.value?.zonas || []) {
    for (const l of z.lotes || []) used.add(l.codigo.toUpperCase())
  }
  for (const l of config.value?.lotes || []) used.add(l.codigo.toUpperCase())
  let n = used.size + 1
  while (used.has(`L${n}`)) n += 1
  return `L${n}`
}

const terrenoProxy = computed<Terreno | null>(() => {
  if (!config.value?.terreno) return null
  const tr = config.value.terreno
  return {
    id: tr.id,
    nombre: tr.nombre,
    geometria: tr.geometria,
    latitud: tr.latitud,
    longitud: tr.longitud,
    nivel_zoom: tr.nivel_zoom,
    metros_por_persona: tr.metros_por_persona ?? 10,
    imagen_referencia: tr.imagen_referencia,
    estado: 'activo',
    estructuras: config.value.estructuras || [],
  }
})

const drawTools = computed(() => [
  { label: t('terrenos.tool.drawZona'), value: 'draw_zona' as MapToolMode, icon: 'pi pi-stop' },
  { label: t('terrenos.tool.drawLote'), value: 'draw_lote' as MapToolMode, icon: 'pi pi-th-large' },
])

const layerTools = computed(() => [
  { label: t('terrenos.layer.map'), value: 'roadmap' as MapLayerMode, icon: 'pi pi-map' },
  { label: t('terrenos.layer.satellite'), value: 'satellite' as MapLayerMode, icon: 'pi pi-globe' },
  { label: t('terrenos.layer.image'), value: 'imagen' as MapLayerMode, icon: 'pi pi-image' },
])

const selectedKey = computed(() => {
  if (selectedKind.value === 'lote' && selectedLote.value) return `lote-${selectedLote.value.id}`
  if (selectedKind.value === 'zona' && selectedZona.value) return `zona-${selectedZona.value.id}`
  if (terrenoProxy.value) return `terreno-${terrenoProxy.value.id}`
  return null
})

const features = computed<MapFeature[]>(() => {
  if (!config.value || !terrenoProxy.value) return []
  const list: MapFeature[] = [
    {
      key: `terreno-${terrenoProxy.value.id}`,
      kind: 'terreno',
      id: terrenoProxy.value.id,
      label: terrenoProxy.value.nombre,
      geometria: terrenoProxy.value.geometria,
    },
  ]
  for (const e of config.value.estructuras || []) {
    list.push({
      key: `estructura-${e.id}`,
      kind: 'estructura',
      id: e.id,
      label: e.nombre,
      geometria: e.geometria,
      color: e.color,
    })
  }
  for (const zona of config.value.zonas || []) {
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
      })
    }
  }
  for (const lote of config.value.lotes || []) {
    list.push({
      key: `lote-${lote.id}`,
      kind: 'lote',
      id: lote.id,
      label: lote.codigo,
      geometria: lote.geometria,
      estado: lote.estado,
      parentId: null,
    })
  }
  return list
})

async function load(options: { quiet?: boolean } = {}): Promise<void> {
  const quiet = options.quiet === true
  if (!quiet) loading.value = true
  try {
    const data = await terrenosService.getConfig(configId.value)
    config.value = data
    if (!quiet) {
      selectedKind.value = null
      selectedZona.value = null
      selectedLote.value = null
    } else {
      if (selectedZona.value) {
        selectedZona.value = (data.zonas || []).find((z) => z.id === selectedZona.value?.id) ?? null
      }
      if (selectedLote.value) {
        const id = selectedLote.value.id
        let found = (data.lotes || []).find((l) => l.id === id) ?? null
        if (!found) {
          for (const z of data.zonas || []) {
            found = (z.lotes || []).find((l) => l.id === id) ?? null
            if (found) {
              selectedZona.value = z
              break
            }
          }
        }
        selectedLote.value = found
      }
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    if (!quiet) await router.push({ name: 'terrenos.map', params: { id: terrenoId.value } })
  } finally {
    if (!quiet) loading.value = false
  }
}

function onSelectFeature(f: MapFeature): void {
  if (f.kind === 'zona') {
    selectedKind.value = 'zona'
    selectedZona.value = (config.value?.zonas || []).find((z) => z.id === f.id) || null
    selectedLote.value = null
  } else if (f.kind === 'lote') {
    const direct = (config.value?.lotes || []).find((l) => l.id === f.id)
    if (direct) {
      selectedKind.value = 'lote'
      selectedZona.value = null
      selectedLote.value = direct
    } else {
      for (const zona of config.value?.zonas || []) {
        const lote = (zona.lotes || []).find((l) => l.id === f.id)
        if (lote) {
          selectedKind.value = 'lote'
          selectedZona.value = zona
          selectedLote.value = lote
          break
        }
      }
    }
  } else {
    selectedKind.value = null
    selectedZona.value = null
    selectedLote.value = null
  }
  if (isMobile.value) drawerOpen.value = true
}

function overlapsEstructura(path: Array<{ lat: number; lng: number }>): boolean {
  return (config.value?.estructuras || []).some((e) => pathIntersectsGeo(path, e.geometria))
}

async function onDrawn(payload: { geometria: GeoJsonGeometry; path: Array<{ lat: number; lng: number }> }): Promise<void> {
  if (!config.value || !terrenoProxy.value || !canEdit.value) return
  const measures = measurePaths(payload.path)
  try {
    if (tool.value === 'draw_zona') {
      saving.value = true
      if (!terrenoProxy.value.geometria) {
        toast.add({ severity: 'warn', summary: t('common.warning'), detail: t('terrenos.needTerrenoFirst'), life: 4000 })
        return
      }
      if (!isPathContainedInGeo(payload.path, terrenoProxy.value.geometria)) {
        toast.add({ severity: 'warn', summary: t('common.warning'), detail: t('terrenos.zonaOutsideTerreno'), life: 4500 })
        return
      }
      await terrenosService.createZona(config.value.id, {
        nombre: `${t('terrenos.zona')} ${(config.value.zonas?.length || 0) + 1}`,
        geometria: payload.geometria,
        area: measures.area,
        perimetro: measures.perimetro,
      })
      await load({ quiet: true })
      setTool('select')
    } else if (tool.value === 'draw_lote') {
      if (!terrenoProxy.value.geometria) {
        toast.add({ severity: 'warn', summary: t('common.warning'), detail: t('terrenos.needTerrenoFirst'), life: 4000 })
        return
      }
      if (overlapsEstructura(payload.path)) {
        toast.add({ severity: 'warn', summary: t('common.warning'), detail: t('terrenos.loteOverlapsEstructura'), life: 4500 })
        return
      }
      const zona = selectedZona.value
      if (zona) {
        if (!zona.geometria) {
          toast.add({ severity: 'warn', summary: t('common.warning'), detail: t('terrenos.needZonaGeometry'), life: 4000 })
          return
        }
        if (!isPathContainedInGeo(payload.path, zona.geometria)) {
          toast.add({ severity: 'warn', summary: t('common.warning'), detail: t('terrenos.loteOutsideZona'), life: 4500 })
          return
        }
      } else if (!isPathContainedInGeo(payload.path, terrenoProxy.value.geometria)) {
        toast.add({ severity: 'warn', summary: t('common.warning'), detail: t('terrenos.loteOutsideTerreno'), life: 4500 })
        return
      }

      const suggested = suggestNextCodigo()
      pendingLote.value = {
        geometria: payload.geometria,
        area: measures.area,
        perimetro: measures.perimetro,
        zona: zona ?? null,
      }
      loteForm.value = {
        codigo: suggested,
        nombre: `${t('terrenos.lote')} ${suggested}`,
      }
      continueDrawLote.value = true
      // Pausar dibujo mientras se pide el código; al cerrar se retoma draw_lote
      tool.value = 'select'
      loteDialogVisible.value = true
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

function cancelLoteDialog(): void {
  loteDialogVisible.value = false
  pendingLote.value = null
  loteForm.value = { codigo: '', nombre: '' }
  if (continueDrawLote.value) {
    tool.value = 'draw_lote'
  }
}

async function confirmLoteDialog(): Promise<void> {
  if (!config.value || !pendingLote.value) return
  const codigo = loteForm.value.codigo.trim()
  if (!codigo) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('terrenos.codigoRequired'),
      life: 3500,
    })
    return
  }
  saving.value = true
  try {
    const payload = {
      codigo,
      nombre: loteForm.value.nombre.trim() || codigo,
      geometria: pendingLote.value.geometria,
      area: pendingLote.value.area,
      perimetro: pendingLote.value.perimetro,
      tipo_capacidad: 'calculada' as const,
    }
    if (pendingLote.value.zona) {
      await terrenosService.createLote(pendingLote.value.zona.id, payload)
    } else {
      await terrenosService.createLoteOnConfig(config.value.id, payload)
    }
    const keepDrawing = continueDrawLote.value
    cancelLoteDialog()
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.saveSuccess'), life: 2200 })
    await load({ quiet: true })
    if (keepDrawing) {
      continueDrawLote.value = true
      tool.value = 'draw_lote'
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function onEdited(payload: {
  feature: MapFeature
  geometria: GeoJsonGeometry
  path: Array<{ lat: number; lng: number }>
}): Promise<void> {
  if (!config.value || !terrenoProxy.value || !canEdit.value) return
  const measures = measurePaths(payload.path)

  if (payload.feature.kind === 'lote') {
    if (overlapsEstructura(payload.path)) {
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('terrenos.loteOverlapsEstructura'),
        life: 4500,
      })
      await load({ quiet: true })
      return
    }
    const lote =
      selectedLote.value?.id === payload.feature.id
        ? selectedLote.value
        : (config.value.lotes || []).find((l) => l.id === payload.feature.id) ||
          (config.value.zonas || []).flatMap((z) => z.lotes || []).find((l) => l.id === payload.feature.id) ||
          null
    const zona =
      lote?.zona_terreno_id != null
        ? (config.value.zonas || []).find((z) => z.id === lote.zona_terreno_id) || null
        : null
    if (zona?.geometria) {
      if (!isPathContainedInGeo(payload.path, zona.geometria)) {
        toast.add({
          severity: 'warn',
          summary: t('common.warning'),
          detail: t('terrenos.loteOutsideZona'),
          life: 4500,
        })
        await load({ quiet: true })
        return
      }
    } else if (terrenoProxy.value.geometria && !isPathContainedInGeo(payload.path, terrenoProxy.value.geometria)) {
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('terrenos.loteOutsideTerreno'),
        life: 4500,
      })
      await load({ quiet: true })
      return
    }

    saving.value = true
    try {
      await terrenosService.updateLote(payload.feature.id, {
        geometria: payload.geometria,
        area: measures.area,
        perimetro: measures.perimetro,
      })
      await load({ quiet: true })
    } catch (error) {
      toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
      await load({ quiet: true })
    } finally {
      saving.value = false
    }
    return
  }

  if (payload.feature.kind === 'zona') {
    if (!terrenoProxy.value.geometria || !isPathContainedInGeo(payload.path, terrenoProxy.value.geometria)) {
      toast.add({
        severity: 'warn',
        summary: t('common.warning'),
        detail: t('terrenos.zonaOutsideTerreno'),
        life: 4500,
      })
      await load({ quiet: true })
      return
    }
    saving.value = true
    try {
      await terrenosService.updateZona(payload.feature.id, {
        geometria: payload.geometria,
        area: measures.area,
        perimetro: measures.perimetro,
      })
      await load({ quiet: true })
    } catch (error) {
      toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
      await load({ quiet: true })
    } finally {
      saving.value = false
    }
  }
}

async function saveSelection(): Promise<void> {
  if (!canEdit.value) return
  saving.value = true
  try {
    if (selectedKind.value === 'lote' && selectedLote.value) {
      await terrenosService.updateLote(selectedLote.value.id, {
        codigo: selectedLote.value.codigo,
        nombre: selectedLote.value.nombre,
        tipo_capacidad: selectedLote.value.tipo_capacidad,
        capacidad_maxima: selectedLote.value.capacidad_maxima ?? undefined,
        descripcion: selectedLote.value.descripcion,
      })
    } else if (selectedKind.value === 'zona' && selectedZona.value) {
      await terrenosService.updateZona(selectedZona.value.id, {
        nombre: selectedZona.value.nombre,
        descripcion: selectedZona.value.descripcion,
        color: selectedZona.value.color,
      })
    } else if (config.value) {
      await terrenosService.updateConfig(config.value.id, {
        nombre: config.value.nombre,
        descripcion: config.value.descripcion,
      })
    }
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.saveSuccess'), life: 2200 })
    await load({ quiet: true })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function removeSelection(): Promise<void> {
  if (!canEdit.value) return
  saving.value = true
  try {
    if (selectedKind.value === 'lote' && selectedLote.value) {
      await terrenosService.removeLote(selectedLote.value.id)
    } else if (selectedKind.value === 'zona' && selectedZona.value) {
      await terrenosService.removeZona(selectedZona.value.id)
    }
    selectedLote.value = null
    selectedZona.value = null
    selectedKind.value = null
    await load({ quiet: true })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function onRemoveFeature(feature: MapFeature): Promise<void> {
  if (!canEdit.value || !config.value) return
  if (feature.kind === 'lote') {
    const ok = window.confirm(t('terrenos.deleteLoteConfirm'))
    if (!ok) return
    saving.value = true
    try {
      await terrenosService.removeLote(feature.id)
      selectedLote.value = null
      selectedKind.value = null
      await load({ quiet: true })
    } catch (error) {
      toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    } finally {
      saving.value = false
    }
    return
  }
  if (feature.kind === 'zona') {
    const ok = window.confirm(t('terrenos.deleteZonaConfirm'))
    if (!ok) return
    saving.value = true
    try {
      await terrenosService.removeZona(feature.id)
      selectedZona.value = null
      selectedLote.value = null
      selectedKind.value = null
      await load({ quiet: true })
    } catch (error) {
      toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    } finally {
      saving.value = false
    }
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="terreno-map-page">
    <header class="terreno-map-page__toolbar">
      <Button
        icon="pi pi-arrow-left"
        text
        rounded
        @click="router.push({ name: 'terrenos.map', params: { id: terrenoId } })"
      />
      <div class="title">
        <h1 class="pj-display">{{ config?.nombre || t('terrenos.configMapTitle') }}</h1>
        <p class="muted">{{ terrenoProxy?.nombre }}</p>
      </div>

      <div class="toolbar-group">
        <span class="toolbar-group__label">{{ t('terrenos.toolbar.selectGroup') }}</span>
        <Button
          :label="t('terrenos.tool.select')"
          icon="pi pi-cursor"
          size="small"
          :severity="tool === 'select' ? 'primary' : 'secondary'"
          :outlined="tool !== 'select'"
          @click="setTool('select')"
        />
      </div>

      <div class="toolbar-group">
        <span class="toolbar-group__label">{{ t('terrenos.toolbar.drawGroup') }}</span>
        <Button
          v-for="opt in drawTools"
          :key="opt.value"
          :label="opt.label"
          :icon="opt.icon"
          size="small"
          :severity="tool === opt.value ? 'primary' : 'secondary'"
          :outlined="tool !== opt.value"
          :disabled="!canEdit"
          @click="setTool(opt.value)"
        />
      </div>

      <div class="toolbar-group toolbar-group--view">
        <span class="toolbar-group__label">{{ t('terrenos.toolbar.viewGroup') }}</span>
        <Button
          v-for="opt in layerTools"
          :key="opt.value"
          :label="opt.label"
          :icon="opt.icon"
          size="small"
          :severity="layer === opt.value ? 'contrast' : 'secondary'"
          :outlined="layer !== opt.value"
          @click="layer = opt.value"
        />
      </div>

      <Button v-if="isMobile" icon="pi pi-list" text rounded @click="drawerOpen = true" />
    </header>

    <PageLoader v-if="loading" />
    <div v-else class="terreno-map-page__body">
      <div class="map-wrap">
        <TerrenoMapCanvas
          :features="features"
          :center="
            terrenoProxy?.latitud && terrenoProxy?.longitud
              ? { lat: terrenoProxy.latitud, lng: terrenoProxy.longitud }
              : null
          "
          :zoom="terrenoProxy?.nivel_zoom || DEFAULT_MAP_ZOOM"
          :tool="tool"
          :layer="layer"
          :imagen-url="terrenoProxy?.imagen_referencia"
          :selected-key="selectedKey"
          :editable-key="editableKey"
          :can-edit="canEdit"
          @select="onSelectFeature"
          @drawn="onDrawn"
          @edited="onEdited"
          @remove-feature="onRemoveFeature"
        />
      </div>

      <aside v-if="!isMobile" class="panel-wrap">
        <TerrenoSidePanel
          mode="config"
          :terreno="terrenoProxy"
          :config="config"
          :selected-kind="selectedKind"
          :selected-zona="selectedZona"
          :selected-lote="selectedLote"
          :can-edit="canEdit"
          :saving="saving"
          @update:zona="selectedZona && Object.assign(selectedZona, $event)"
          @update:lote="selectedLote && Object.assign(selectedLote, $event)"
          @update:config="config && Object.assign(config, $event)"
          @save="saveSelection"
          @remove="removeSelection"
          @select-zona="(z) => { selectedKind = 'zona'; selectedZona = z; selectedLote = null }"
          @select-lote="(l) => { selectedKind = 'lote'; selectedLote = l; selectedZona = l.zona_terreno_id ? (config?.zonas || []).find((z) => z.id === l.zona_terreno_id) || null : null }"
        />
      </aside>
    </div>

    <Drawer v-model:visible="drawerOpen" position="bottom" :style="{ height: '70vh' }">
      <TerrenoSidePanel
        mode="config"
        :terreno="terrenoProxy"
        :config="config"
        :selected-kind="selectedKind"
        :selected-zona="selectedZona"
        :selected-lote="selectedLote"
        :can-edit="canEdit"
        :saving="saving"
        @update:zona="selectedZona && Object.assign(selectedZona, $event)"
        @update:lote="selectedLote && Object.assign(selectedLote, $event)"
        @update:config="config && Object.assign(config, $event)"
        @save="saveSelection"
        @remove="removeSelection"
        @select-zona="(z) => { selectedKind = 'zona'; selectedZona = z; selectedLote = null }"
        @select-lote="(l) => { selectedKind = 'lote'; selectedLote = l; selectedZona = l.zona_terreno_id ? (config?.zonas || []).find((z) => z.id === l.zona_terreno_id) || null : null }"
      />
    </Drawer>

    <Dialog
      v-model:visible="loteDialogVisible"
      modal
      :header="t('terrenos.nuevoLote')"
      :style="{ width: 'min(420px, 95vw)' }"
      :closable="!saving"
      @hide="cancelLoteDialog"
    >
      <div class="lote-dialog-form">
        <label>
          <span>{{ t('terrenos.codigo') }} *</span>
          <InputText v-model="loteForm.codigo" autofocus class="w-full" :disabled="saving" />
        </label>
        <label>
          <span>{{ t('terrenos.nombre') }}</span>
          <InputText v-model="loteForm.nombre" class="w-full" :disabled="saving" />
        </label>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" text :disabled="saving" @click="cancelLoteDialog" />
        <Button :label="t('common.save')" icon="pi pi-check" :loading="saving" @click="confirmLoteDialog" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.terreno-map-page {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: calc(100vh - 6rem);
}
.terreno-map-page__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem 1rem;
}
.toolbar-group {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.5rem;
  border-radius: 10px;
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 85%, transparent);
}
.toolbar-group--view {
  border-style: dashed;
}
.toolbar-group__label {
  width: 100%;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--pj-muted, #667);
}
.title {
  flex: 1;
  min-width: 140px;
}
.title h1 {
  margin: 0;
  font-size: 1.2rem;
}
.muted {
  margin: 0;
  color: var(--pj-muted, #667);
  font-size: 0.85rem;
}
.terreno-map-page__body {
  display: grid;
  grid-template-columns: 1fr min(340px, 36vw);
  gap: 0.75rem;
  flex: 1;
  min-height: 420px;
}
.map-wrap {
  min-height: 420px;
  border-radius: 12px;
  overflow: hidden;
}
.panel-wrap {
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 80%, transparent);
  overflow: auto;
}
.lote-dialog-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}
.lote-dialog-form label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
}
.w-full {
  width: 100%;
}
@media (max-width: 900px) {
  .terreno-map-page__body {
    grid-template-columns: 1fr;
  }
}
</style>
