<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useMediaQuery } from '@vueuse/core'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import PageLoader from '@/components/PageLoader.vue'
import TerrenoMapCanvas from '@/components/terrenos/TerrenoMapCanvas.vue'
import TerrenoSidePanel from '@/components/terrenos/TerrenoSidePanel.vue'
import type { MapFeature } from '@/components/terrenos/TerrenoMapCanvas.vue'
import { terrenosService } from '@/services/terrenosService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { DEFAULT_MAP_ZOOM, isPathContainedInGeo, measurePaths } from '@/modules/terrenos/geometria'
import type {
  ConfiguracionTerreno,
  EstructuraTerreno,
  GeoJsonGeometry,
  MapLayerMode,
  MapToolMode,
  Terreno,
} from '@/modules/terrenos/types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const terrenoId = computed(() => Number(route.params.id))
const terreno = ref<Terreno | null>(null)
const configuraciones = ref<ConfiguracionTerreno[]>([])
const loading = ref(true)
const saving = ref(false)
const tool = ref<MapToolMode>('select')
const layer = ref<MapLayerMode>('roadmap')
const selectedKind = ref<'terreno' | 'estructura' | null>('terreno')
const selectedEstructura = ref<EstructuraTerreno | null>(null)
const drawerOpen = ref(false)
const isMobile = useMediaQuery('(max-width: 900px)')
const mapRef = ref<InstanceType<typeof TerrenoMapCanvas> | null>(null)

const canEdit = computed(() => can('terrenos.update'))

const drawTools = computed(() => [
  { label: t('terrenos.tool.drawTerreno'), value: 'draw_terreno' as MapToolMode, icon: 'pi pi-map' },
  { label: t('terrenos.tool.drawEstructura'), value: 'draw_estructura' as MapToolMode, icon: 'pi pi-building' },
])

const layerTools = computed(() => [
  { label: t('terrenos.layer.map'), value: 'roadmap' as MapLayerMode, icon: 'pi pi-map' },
  { label: t('terrenos.layer.satellite'), value: 'satellite' as MapLayerMode, icon: 'pi pi-globe' },
  { label: t('terrenos.layer.image'), value: 'imagen' as MapLayerMode, icon: 'pi pi-image' },
])

const editableKey = computed(() => {
  if (!canEdit.value || !terreno.value) return null
  if (tool.value === 'draw_estructura') return null
  if (tool.value === 'draw_terreno' && terreno.value.geometria) {
    return `terreno-${terreno.value.id}`
  }
  if (tool.value === 'select' && selectedKind.value === 'terreno') {
    return terreno.value.geometria ? `terreno-${terreno.value.id}` : null
  }
  if (tool.value === 'select' && selectedKind.value === 'estructura' && selectedEstructura.value?.geometria) {
    return `estructura-${selectedEstructura.value.id}`
  }
  return null
})

function setDrawTool(mode: MapToolMode): void {
  tool.value = mode
  if (mode === 'draw_terreno') {
    selectedKind.value = 'terreno'
    selectedEstructura.value = null
  }
}

function setSelectTool(): void {
  tool.value = 'select'
}

const selectedKey = computed(() => {
  if (selectedKind.value === 'estructura' && selectedEstructura.value) {
    return `estructura-${selectedEstructura.value.id}`
  }
  if (terreno.value) return `terreno-${terreno.value.id}`
  return null
})

const features = computed<MapFeature[]>(() => {
  if (!terreno.value) return []
  const list: MapFeature[] = [
    {
      key: `terreno-${terreno.value.id}`,
      kind: 'terreno',
      id: terreno.value.id,
      label: terreno.value.nombre,
      geometria: terreno.value.geometria,
    },
  ]
  for (const estructura of terreno.value.estructuras || []) {
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

async function load(options: { quiet?: boolean } = {}): Promise<void> {
  const quiet = options.quiet === true
  if (!quiet) loading.value = true
  try {
    const data = await terrenosService.get(terrenoId.value)
    terreno.value = data
    configuraciones.value = data.configuraciones?.length
      ? data.configuraciones
      : await terrenosService.listConfigs(terrenoId.value)
    if (!quiet) {
      selectedKind.value = 'terreno'
      selectedEstructura.value = null
    } else if (selectedEstructura.value) {
      selectedEstructura.value =
        (data.estructuras || []).find((e) => e.id === selectedEstructura.value?.id) ?? null
      if (!selectedEstructura.value && selectedKind.value === 'estructura') selectedKind.value = 'terreno'
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    if (!quiet) await router.push({ name: 'terrenos' })
  } finally {
    if (!quiet) loading.value = false
  }
}

function onSelectFeature(f: MapFeature): void {
  if (f.kind === 'estructura') {
    selectedKind.value = 'estructura'
    selectedEstructura.value = (terreno.value?.estructuras || []).find((e) => e.id === f.id) || null
  } else {
    selectedKind.value = 'terreno'
    selectedEstructura.value = null
  }
  if (isMobile.value) drawerOpen.value = true
}

async function onDrawn(payload: { geometria: GeoJsonGeometry; path: Array<{ lat: number; lng: number }> }): Promise<void> {
  if (!terreno.value || !canEdit.value) return
  const measures = measurePaths(payload.path)
  saving.value = true
  try {
    if (tool.value === 'draw_terreno') {
      const center = payload.path[0]
      terreno.value = await terrenosService.update(terreno.value.id, {
        geometria: payload.geometria,
        area_total: measures.area,
        perimetro: measures.perimetro,
        latitud: center?.lat,
        longitud: center?.lng,
      })
    } else if (tool.value === 'draw_estructura') {
      if (!terreno.value.geometria) {
        toast.add({
          severity: 'warn',
          summary: t('common.warning'),
          detail: t('terrenos.needTerrenoFirst'),
          life: 4000,
        })
        return
      }
      if (!isPathContainedInGeo(payload.path, terreno.value.geometria)) {
        toast.add({
          severity: 'warn',
          summary: t('common.warning'),
          detail: t('terrenos.estructuraOutsideTerreno'),
          life: 4500,
        })
        return
      }
      const n = (terreno.value.estructuras?.length || 0) + 1
      await terrenosService.createEstructura(terreno.value.id, {
        nombre: `${t('terrenos.estructura')} ${n}`,
        tipo: 'general',
        geometria: payload.geometria,
        area: measures.area,
        perimetro: measures.perimetro,
        color: '#6d4c41',
      })
      await load({ quiet: true })
    }
    tool.value = 'select'
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function saveSelection(): Promise<void> {
  if (!terreno.value || !canEdit.value) return
  saving.value = true
  try {
    if (selectedKind.value === 'estructura' && selectedEstructura.value) {
      await terrenosService.updateEstructura(selectedEstructura.value.id, {
        nombre: selectedEstructura.value.nombre,
        tipo: selectedEstructura.value.tipo,
        descripcion: selectedEstructura.value.descripcion,
        color: selectedEstructura.value.color,
      })
    } else {
      await terrenosService.update(terreno.value.id, {
        nombre: terreno.value.nombre,
        descripcion: terreno.value.descripcion,
        metros_por_persona: terreno.value.metros_por_persona,
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
  if (selectedKind.value !== 'estructura' || !selectedEstructura.value) return
  saving.value = true
  try {
    await terrenosService.removeEstructura(selectedEstructura.value.id)
    selectedEstructura.value = null
    selectedKind.value = 'terreno'
    await load({ quiet: true })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function onRemoveFeature(feature: MapFeature): Promise<void> {
  if (!canEdit.value || feature.kind !== 'estructura') return
  const ok = window.confirm(t('terrenos.deleteEstructuraConfirm'))
  if (!ok) return
  saving.value = true
  try {
    await terrenosService.removeEstructura(feature.id)
    selectedEstructura.value = null
    selectedKind.value = 'terreno'
    await load({ quiet: true })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function uploadImagen(file: File): Promise<void> {
  if (!terreno.value) return
  try {
    terreno.value = await terrenosService.uploadImagen(terreno.value.id, file)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.imageUpdated'), life: 2200 })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  }
}

async function onEdited(payload: {
  feature: MapFeature
  geometria: GeoJsonGeometry
  path: Array<{ lat: number; lng: number }>
}): Promise<void> {
  if (!terreno.value || !canEdit.value) return
  const measures = measurePaths(payload.path)
  saving.value = true
  try {
    if (payload.feature.kind === 'terreno') {
      const center = payload.path[0]
      terreno.value = await terrenosService.update(terreno.value.id, {
        geometria: payload.geometria,
        area_total: measures.area,
        perimetro: measures.perimetro,
        latitud: center?.lat,
        longitud: center?.lng,
      })
    } else if (payload.feature.kind === 'estructura') {
      await terrenosService.updateEstructura(payload.feature.id, {
        geometria: payload.geometria,
        area: measures.area,
        perimetro: measures.perimetro,
      })
      await load({ quiet: true })
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
    await load({ quiet: true })
  } finally {
    saving.value = false
  }
}

function openConfig(cfg: ConfiguracionTerreno): void {
  void router.push({
    name: 'terrenos.config',
    params: { id: terrenoId.value, configId: cfg.id },
  })
}

async function createConfig(): Promise<void> {
  if (!terreno.value || !canEdit.value) return
  saving.value = true
  try {
    const n = configuraciones.value.length + 1
    const created = await terrenosService.createConfig(terreno.value.id, {
      nombre: `${t('terrenos.configuracion')} ${n}`,
    })
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.saveSuccess'), life: 2200 })
    openConfig(created)
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function duplicateConfig(cfg: ConfiguracionTerreno): Promise<void> {
  if (!canEdit.value) return
  saving.value = true
  try {
    const copy = await terrenosService.duplicateConfig(cfg.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.saveSuccess'), life: 2200 })
    await load({ quiet: true })
    openConfig(copy)
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="terreno-map-page">
    <header class="terreno-map-page__toolbar">
      <Button icon="pi pi-arrow-left" text rounded @click="router.push({ name: 'terrenos' })" />
      <div class="title">
        <h1 class="pj-display">{{ terreno?.nombre || t('terrenos.title') }}</h1>
      </div>

      <div class="toolbar-group" role="group" :aria-label="t('terrenos.toolbar.selectGroup')">
        <span class="toolbar-group__label">{{ t('terrenos.toolbar.selectGroup') }}</span>
        <Button
          :label="t('terrenos.tool.select')"
          icon="pi pi-cursor"
          size="small"
          :severity="tool === 'select' ? 'primary' : 'secondary'"
          :outlined="tool !== 'select'"
          @click="setSelectTool()"
        />
      </div>

      <div class="toolbar-group" role="group" :aria-label="t('terrenos.toolbar.drawGroup')">
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
          @click="setDrawTool(opt.value)"
        />
      </div>

      <div class="toolbar-group toolbar-group--view" role="group" :aria-label="t('terrenos.toolbar.viewGroup')">
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

      <Button
        v-if="isMobile"
        icon="pi pi-list"
        text
        rounded
        :aria-label="t('terrenos.panel')"
        @click="drawerOpen = true"
      />
    </header>

    <PageLoader v-if="loading" />
    <div v-else class="terreno-map-page__body">
      <div class="map-wrap">
        <TerrenoMapCanvas
          ref="mapRef"
          :features="features"
          :center="terreno?.latitud && terreno?.longitud ? { lat: terreno.latitud, lng: terreno.longitud } : null"
          :zoom="terreno?.nivel_zoom || DEFAULT_MAP_ZOOM"
          :tool="tool"
          :layer="layer"
          :imagen-url="terreno?.imagen_referencia"
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
          mode="plantilla"
          :terreno="terreno"
          :configuraciones="configuraciones"
          :selected-kind="selectedKind"
          :selected-zona="null"
          :selected-lote="null"
          :selected-estructura="selectedEstructura"
          :can-edit="canEdit"
          :saving="saving"
          @update:terreno="Object.assign(terreno || {}, $event)"
          @update:estructura="selectedEstructura && Object.assign(selectedEstructura, $event)"
          @save="saveSelection"
          @remove="removeSelection"
          @upload="uploadImagen"
          @select-estructura="(e) => { selectedKind = 'estructura'; selectedEstructura = e }"
          @open-config="openConfig"
          @create-config="createConfig"
          @duplicate-config="duplicateConfig"
        />
      </aside>
    </div>

    <Drawer v-model:visible="drawerOpen" position="bottom" class="terreno-drawer" :style="{ height: '70vh' }">
      <TerrenoSidePanel
        mode="plantilla"
        :terreno="terreno"
        :configuraciones="configuraciones"
        :selected-kind="selectedKind"
        :selected-zona="null"
        :selected-lote="null"
        :selected-estructura="selectedEstructura"
        :can-edit="canEdit"
        :saving="saving"
        @update:terreno="Object.assign(terreno || {}, $event)"
        @update:estructura="selectedEstructura && Object.assign(selectedEstructura, $event)"
        @save="saveSelection"
        @remove="removeSelection"
        @upload="uploadImagen"
        @select-estructura="(e) => { selectedKind = 'estructura'; selectedEstructura = e }"
        @open-config="openConfig"
        @create-config="createConfig"
        @duplicate-config="duplicateConfig"
      />
    </Drawer>
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
  background: color-mix(in srgb, var(--pj-surface, #fff) 88%, transparent);
}

.toolbar-group--view {
  border-style: dashed;
  background: color-mix(in srgb, var(--pj-muted, #667) 6%, transparent);
}

.toolbar-group__label {
  display: block;
  width: 100%;
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: var(--pj-muted, #667);
  margin-bottom: 0.1rem;
}

.title {
  flex: 1;
  min-width: 120px;
  align-self: center;
}

.title h1 {
  margin: 0;
  font-size: 1.2rem;
}

.terreno-map-page__body {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 0.75rem;
  flex: 1;
  min-height: 480px;
}

.map-wrap,
.panel-wrap {
  min-height: 480px;
  border-radius: 12px;
  overflow: hidden;
  background: var(--pj-surface, #fff);
  border: 1px solid color-mix(in srgb, var(--pj-border, #ddd) 70%, transparent);
}

@media (max-width: 900px) {
  .terreno-map-page__body {
    grid-template-columns: 1fr;
  }
}
</style>
