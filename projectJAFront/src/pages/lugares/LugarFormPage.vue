<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import PageLoader from '@/components/PageLoader.vue'
import LugarMapPicker from '@/components/lugares/LugarMapPicker.vue'
import TerrenosListPanel from '@/components/terrenos/TerrenosListPanel.vue'
import CabanasListPanel from '@/components/cabanas/CabanasListPanel.vue'
import { lugaresService } from '@/services/lugaresService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import { DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM } from '@/modules/terrenos/geometria'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const lugarId = computed(() => {
  const raw = Number(route.params.id)
  return Number.isFinite(raw) && raw > 0 ? raw : null
})
const isEdit = computed(() => lugarId.value != null)
const loading = ref(false)
const saving = ref(false)
const activeTab = ref('datos')
const form = reactive({
  nombre: '',
  descripcion: '',
  latitud: DEFAULT_MAP_CENTER.lat as number | null,
  longitud: DEFAULT_MAP_CENTER.lng as number | null,
  nivel_zoom: DEFAULT_MAP_ZOOM,
  estado: 'activo',
})

const canEdit = computed(() => (isEdit.value ? can('lugares.update') : can('lugares.create')))
const estadoOptions = computed(() => [
  { label: t('lugares.activo'), value: 'activo' },
  { label: t('lugares.inactivo'), value: 'inactivo' },
])

usePageChrome(() => ({
  title: isEdit.value ? t('lugares.edit') : t('lugares.create'),
  subtitle: t('lugares.formLead'),
  backTo: { name: 'lugares' },
}))

function onMapChange(payload: { latitud: number; longitud: number; zoom: number }): void {
  form.latitud = payload.latitud
  form.longitud = payload.longitud
  form.nivel_zoom = payload.zoom
}

async function load(): Promise<void> {
  if (!lugarId.value) return
  loading.value = true
  try {
    const lugar = await lugaresService.get(lugarId.value)
    form.nombre = lugar.nombre
    form.descripcion = lugar.descripcion ?? ''
    form.latitud = lugar.latitud ?? DEFAULT_MAP_CENTER.lat
    form.longitud = lugar.longitud ?? DEFAULT_MAP_CENTER.lng
    form.nivel_zoom = lugar.nivel_zoom ?? DEFAULT_MAP_ZOOM
    form.estado = lugar.estado || 'activo'
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!form.nombre.trim() || !canEdit.value) return
  saving.value = true
  try {
    const payload = {
      nombre: form.nombre.trim(),
      descripcion: form.descripcion.trim() || null,
      latitud: form.latitud,
      longitud: form.longitud,
      nivel_zoom: form.nivel_zoom,
      estado: form.estado,
    }
    const saved = isEdit.value && lugarId.value
      ? await lugaresService.update(lugarId.value, payload)
      : await lugaresService.create(payload)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('lugares.saveSuccess'), life: 2500 })
    if (!isEdit.value) {
      await router.replace({ name: 'lugares.edit', params: { id: saved.id } })
    }
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

watch(lugarId, (id, previous) => {
  if (id && id !== previous) void load()
})

onMounted(() => void load())
</script>

<template>
  <section class="pj-page lugar-form">
    <PageLoader v-if="loading" />
    <Tabs v-else v-model:value="activeTab" class="lugar-tabs">
      <TabList>
        <Tab value="datos">
          <i class="pi pi-map-marker" />
          <span>{{ t('lugares.tabDatos') }}</span>
        </Tab>
        <Tab v-if="isEdit" value="terrenos">
          <i class="pi pi-map" />
          <span>{{ t('lugares.tabTerrenos') }}</span>
        </Tab>
        <Tab v-if="isEdit" value="alojamiento">
          <i class="pi pi-building" />
          <span>{{ t('lugares.tabAlojamiento') }}</span>
        </Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="datos">
          <form class="lugar-form__grid" @submit.prevent="save">
            <div class="lugar-form__fields">
              <label class="field">
                <span>{{ t('lugares.nombre') }} *</span>
                <InputText v-model="form.nombre" class="w-full" :disabled="!canEdit" />
              </label>
              <label class="field">
                <span>{{ t('lugares.descripcion') }}</span>
                <Textarea v-model="form.descripcion" rows="4" class="w-full" :disabled="!canEdit" />
              </label>
              <label class="field">
                <span>{{ t('lugares.estado') }}</span>
                <Select
                  v-model="form.estado"
                  :options="estadoOptions"
                  option-label="label"
                  option-value="value"
                  class="w-full"
                  :disabled="!canEdit"
                />
              </label>
              <div class="lugar-form__actions">
                <Button type="button" :label="t('common.cancel')" text @click="router.push({ name: 'lugares' })" />
                <Button
                  type="submit"
                  :label="t('common.save')"
                  icon="pi pi-save"
                  :loading="saving"
                  :disabled="!canEdit || !form.nombre.trim()"
                />
              </div>
            </div>
            <LugarMapPicker
              :latitud="form.latitud"
              :longitud="form.longitud"
              :zoom="form.nivel_zoom"
              :disabled="!canEdit"
              @change="onMapChange"
            />
          </form>
        </TabPanel>
        <TabPanel v-if="lugarId" value="terrenos">
          <TerrenosListPanel
            embedded
            :lugar-id="lugarId"
            :lugar-latitud="form.latitud"
            :lugar-longitud="form.longitud"
            :lugar-zoom="form.nivel_zoom"
          />
        </TabPanel>
        <TabPanel v-if="lugarId" value="alojamiento">
          <CabanasListPanel embedded :lugar-id="lugarId" />
        </TabPanel>
      </TabPanels>
    </Tabs>
  </section>
</template>

<style scoped>
.lugar-form {
  min-height: calc(100vh - 6.5rem);
}
.lugar-tabs {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: calc(100vh - 6.5rem);
  background: color-mix(in srgb, var(--pj-bg-elevated) 94%, transparent);
  border: 1px solid color-mix(in srgb, var(--pj-border) 65%, transparent);
  border-radius: 14px;
  overflow: hidden;
}
.lugar-tabs :deep(.p-tablist-tab-list) {
  gap: 0;
  padding: 0 0.5rem;
  background: color-mix(in srgb, var(--pj-bg-muted) 55%, transparent);
  border-bottom: 1px solid color-mix(in srgb, var(--pj-border) 60%, transparent);
}
.lugar-tabs :deep(.p-tab) {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.85rem 1rem;
}
.lugar-tabs :deep(.p-tabpanels) {
  flex: 1;
  min-height: 0;
  padding: 0.75rem 0.85rem 0.9rem;
}
.lugar-tabs :deep(.p-tabpanel) {
  height: 100%;
  padding: 0;
}
.lugar-form__grid {
  display: grid;
  grid-template-columns: minmax(220px, 280px) minmax(0, 1fr);
  gap: 1rem;
  align-items: stretch;
  min-height: calc(100vh - 12rem);
}
.lugar-form__fields,
.field {
  display: grid;
  gap: 0.4rem;
}
.lugar-form__fields {
  gap: 0.85rem;
  align-content: start;
}
.lugar-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
.lugar-form__grid :deep(.lugar-map),
.lugar-form__grid :deep(.lugar-map__canvas) {
  height: 100%;
  min-height: calc(100vh - 12rem);
}
@media (max-width: 900px) {
  .lugar-form,
  .lugar-tabs {
    min-height: 0;
  }
  .lugar-form__grid {
    grid-template-columns: 1fr;
    min-height: 0;
  }
  .lugar-form__grid :deep(.lugar-map__canvas) {
    min-height: 70vh;
  }
}
</style>
