<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputNumber from 'primevue/inputnumber'
import InputText from 'primevue/inputtext'
import Tag from 'primevue/tag'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import PageLoader from '@/components/PageLoader.vue'
import IconColorPopover from '@/components/IconColorPopover.vue'
import { eventsService } from '@/services/eventsService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import { iconBoxStyle } from '@/utils/iconVisual'
import type { CategoriaSubevento, CriterioEvaluacion } from '@/modules/events/types'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const { can } = usePermission()

const allowedTabs = ['categorias', 'criterios'] as const
type CatalogTab = (typeof allowedTabs)[number]

const tab = ref<CatalogTab>(
  allowedTabs.includes(route.query.tab as CatalogTab) ? (route.query.tab as CatalogTab) : 'categorias',
)

watch(tab, (value) => {
  if (route.query.tab !== value) {
    void router.replace({ query: { ...route.query, tab: value } })
  }
})

const canCreate = computed(() => can('events.create'))
const canUpdate = computed(() => can('events.update'))
const canDelete = computed(() => can('events.delete'))

const loading = ref(false)
const saving = ref(false)
const categorias = ref<CategoriaSubevento[]>([])
const criterios = ref<CriterioEvaluacion[]>([])

const catDialogVisible = ref(false)
const editingCategoria = ref<CategoriaSubevento | null>(null)
const catForm = reactive({
  nombre: '',
  color: '#2563eb',
  icono: 'pi pi-tag',
  orden: 0,
  estado: true,
  maneja_puntos: true,
  maneja_fecha_inicio: false,
  maneja_fecha_fin: false,
})

const critDialogVisible = ref(false)
const editingCriterio = ref<CriterioEvaluacion | null>(null)
const critForm = reactive({
  nombre: '',
  descripcion: '',
  color: '#2563eb',
  icono: 'pi pi-list-check',
  orden: 0,
  estado: true,
})

const deleteCategoria = ref<CategoriaSubevento | null>(null)
const deleteCriterio = ref<CriterioEvaluacion | null>(null)
const deleting = ref(false)

usePageChrome(() => ({
  title: t('events.catalogos.title'),
  subtitle: t('events.catalogos.subtitle'),
  backTo: { name: 'events' },
  actions:
    canCreate.value
      ? [
          {
            key: 'new',
            label:
              tab.value === 'criterios'
                ? t('events.wizard.criteriaCreate')
                : t('events.wizard.catAdd'),
            icon: 'pi pi-plus',
            onClick: () => (tab.value === 'criterios' ? openCreateCriterio() : openCreateCategoria()),
          },
        ]
      : [],
}))

function resetCategoriaForm(): void {
  catForm.nombre = ''
  catForm.color = '#2563eb'
  catForm.icono = 'pi pi-tag'
  catForm.orden = (categorias.value.at(-1)?.orden ?? 0) + 1
  catForm.estado = true
  catForm.maneja_puntos = true
  catForm.maneja_fecha_inicio = false
  catForm.maneja_fecha_fin = false
}

function resetCriterioForm(): void {
  critForm.nombre = ''
  critForm.descripcion = ''
  critForm.color = '#2563eb'
  critForm.icono = 'pi pi-list-check'
  critForm.orden = (criterios.value.at(-1)?.orden ?? 0) + 1
  critForm.estado = true
}

function openCreateCategoria(): void {
  editingCategoria.value = null
  resetCategoriaForm()
  catDialogVisible.value = true
}

function openEditCategoria(item: CategoriaSubevento): void {
  editingCategoria.value = item
  catForm.nombre = item.nombre
  catForm.color = item.color || '#2563eb'
  catForm.icono = item.icono || 'pi pi-tag'
  catForm.orden = item.orden ?? 0
  catForm.estado = item.estado !== false
  catForm.maneja_puntos = item.maneja_puntos !== false
  catForm.maneja_fecha_inicio = !!item.maneja_fecha_inicio
  catForm.maneja_fecha_fin = !!item.maneja_fecha_fin
  catDialogVisible.value = true
}

function openCreateCriterio(): void {
  editingCriterio.value = null
  resetCriterioForm()
  critDialogVisible.value = true
}

function openEditCriterio(item: CriterioEvaluacion): void {
  editingCriterio.value = item
  critForm.nombre = item.nombre
  critForm.descripcion = item.descripcion || ''
  critForm.color = item.color || '#2563eb'
  critForm.icono = item.icono || 'pi pi-list-check'
  critForm.orden = item.orden ?? 0
  critForm.estado = item.estado !== false
  critDialogVisible.value = true
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const [cats, crits] = await Promise.all([
      eventsService.categoriasSubevento({ todos: true }),
      eventsService.criteriosEvaluacion({ todos: true }),
    ])
    categorias.value = cats
    criterios.value = crits
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

async function saveCategoria(): Promise<void> {
  if (!catForm.nombre.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('events.wizard.catNameRequired'),
      life: 3000,
    })
    return
  }
  saving.value = true
  try {
    const payload = {
      nombre: catForm.nombre.trim(),
      color: catForm.color || null,
      icono: catForm.icono.trim() || null,
      orden: catForm.orden,
      estado: catForm.estado,
      maneja_puntos: catForm.maneja_puntos,
      maneja_fecha_inicio: catForm.maneja_fecha_inicio,
      maneja_fecha_fin: catForm.maneja_fecha_fin,
    }
    if (editingCategoria.value) {
      await eventsService.updateCategoriaSubevento(editingCategoria.value.id, payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('events.wizard.catUpdateSuccess'),
        life: 2200,
      })
    } else {
      await eventsService.createCategoriaSubevento(payload)
      toast.add({
        severity: 'success',
        summary: t('common.success'),
        detail: t('events.wizard.catCreateSuccess'),
        life: 2200,
      })
    }
    catDialogVisible.value = false
    await load()
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

async function saveCriterio(): Promise<void> {
  if (!critForm.nombre.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('common.warning'),
      detail: t('events.wizard.criteriaName'),
      life: 3000,
    })
    return
  }
  saving.value = true
  try {
    const payload = {
      nombre: critForm.nombre.trim(),
      descripcion: critForm.descripcion.trim() || null,
      color: critForm.color || null,
      icono: critForm.icono.trim() || null,
      orden: critForm.orden,
      estado: critForm.estado,
    }
    if (editingCriterio.value) {
      await eventsService.updateCriterioEvaluacion(editingCriterio.value.id, payload)
    } else {
      await eventsService.createCriterioEvaluacion(payload)
    }
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.criteriaSaved'),
      life: 2200,
    })
    critDialogVisible.value = false
    await load()
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

async function confirmDeleteCategoria(): Promise<void> {
  if (!deleteCategoria.value) return
  deleting.value = true
  try {
    await eventsService.removeCategoriaSubevento(deleteCategoria.value.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.catDeleteSuccess'),
      life: 2200,
    })
    deleteCategoria.value = null
    await load()
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

async function confirmDeleteCriterio(): Promise<void> {
  if (!deleteCriterio.value) return
  deleting.value = true
  try {
    await eventsService.removeCriterioEvaluacion(deleteCriterio.value.id)
    toast.add({
      severity: 'success',
      summary: t('common.success'),
      detail: t('events.wizard.criteriaDeleted'),
      life: 2200,
    })
    deleteCriterio.value = null
    await load()
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

onMounted(() => {
  void load()
})
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-page__title">{{ t('events.catalogos.title') }}</h1>
        <p class="pj-page__subtitle">{{ t('events.catalogos.subtitle') }}</p>
      </div>
      <Button
        v-if="canCreate"
        icon="pi pi-plus"
        :label="tab === 'criterios' ? t('events.wizard.criteriaCreate') : t('events.wizard.catAdd')"
        @click="tab === 'criterios' ? openCreateCriterio() : openCreateCategoria()"
      />
    </header>

    <Tabs v-model:value="tab">
      <TabList>
        <Tab value="categorias">{{ t('events.catalogos.tabCategorias') }}</Tab>
        <Tab value="criterios">{{ t('events.catalogos.tabCriterios') }}</Tab>
      </TabList>
      <TabPanels>
        <TabPanel value="categorias">
          <p class="pj-muted lead">{{ t('events.wizard.catAdminLead') }}</p>
          <div class="pj-panel">
            <PageLoader v-if="loading && !categorias.length" :label="t('common.loading')" />
            <DataTable v-else :value="categorias" data-key="id" striped-rows>
              <template #empty>
                <p class="pj-muted">{{ t('events.wizard.catEmpty') }}</p>
              </template>
              <Column :header="t('events.wizard.catName')">
                <template #body="{ data }">
                  <div class="name-cell">
                    <span class="cat-icon" :style="iconBoxStyle(data.color)">
                      <i :class="data.icono || 'pi pi-tag'" />
                    </span>
                    <div>
                      <strong>{{ data.nombre }}</strong>
                      <div class="flags">
                        <span v-if="data.maneja_puntos" class="flag">{{ t('events.wizard.catFlagPoints') }}</span>
                        <span v-if="data.maneja_fecha_inicio" class="flag">{{ t('events.wizard.catFlagStart') }}</span>
                        <span v-if="data.maneja_fecha_fin" class="flag">{{ t('events.wizard.catFlagEnd') }}</span>
                      </div>
                    </div>
                  </div>
                </template>
              </Column>
              <Column :header="t('productosServicios.estado')" style="width: 8rem">
                <template #body="{ data }">
                  <Tag
                    :value="data.estado !== false ? t('common.active') : t('common.inactive')"
                    :severity="data.estado !== false ? 'success' : 'secondary'"
                  />
                </template>
              </Column>
              <Column :header="t('events.catalogos.origin')" style="width: 8rem">
                <template #body="{ data }">
                  <Tag
                    v-if="data.es_sistema"
                    :value="t('events.catalogos.system')"
                    severity="info"
                  />
                </template>
              </Column>
              <Column v-if="canUpdate || canDelete" :header="t('common.actions')" style="width: 8rem">
                <template #body="{ data }">
                  <div class="actions">
                    <Button
                      v-if="canUpdate"
                      icon="pi pi-pencil"
                      text
                      rounded
                      :aria-label="t('common.edit')"
                      @click="openEditCategoria(data)"
                    />
                    <Button
                      v-if="canDelete && !data.es_sistema"
                      icon="pi pi-trash"
                      text
                      rounded
                      severity="danger"
                      :aria-label="t('common.delete')"
                      @click="deleteCategoria = data"
                    />
                  </div>
                </template>
              </Column>
            </DataTable>
          </div>
        </TabPanel>

        <TabPanel value="criterios">
          <p class="pj-muted lead">{{ t('events.wizard.criteriaAdminLead') }}</p>
          <div class="pj-panel">
            <PageLoader v-if="loading && !criterios.length" :label="t('common.loading')" />
            <DataTable v-else :value="criterios" data-key="id" striped-rows>
              <template #empty>
                <p class="pj-muted">{{ t('events.catalogos.criteriaEmpty') }}</p>
              </template>
              <Column :header="t('events.wizard.criteriaName')">
                <template #body="{ data }">
                  <div class="name-cell">
                    <span class="cat-icon" :style="iconBoxStyle(data.color)">
                      <i :class="data.icono || 'pi pi-list-check'" />
                    </span>
                    <div>
                      <strong>{{ data.nombre }}</strong>
                      <span v-if="data.descripcion" class="pj-muted">{{ data.descripcion }}</span>
                    </div>
                  </div>
                </template>
              </Column>
              <Column :header="t('productosServicios.estado')" style="width: 8rem">
                <template #body="{ data }">
                  <Tag
                    :value="data.estado !== false ? t('common.active') : t('common.inactive')"
                    :severity="data.estado !== false ? 'success' : 'secondary'"
                  />
                </template>
              </Column>
              <Column :header="t('events.catalogos.origin')" style="width: 8rem">
                <template #body="{ data }">
                  <Tag
                    v-if="data.es_sistema"
                    :value="t('events.catalogos.system')"
                    severity="info"
                  />
                </template>
              </Column>
              <Column v-if="canUpdate || canDelete" :header="t('common.actions')" style="width: 8rem">
                <template #body="{ data }">
                  <div class="actions">
                    <Button
                      v-if="canUpdate"
                      icon="pi pi-pencil"
                      text
                      rounded
                      :aria-label="t('common.edit')"
                      @click="openEditCriterio(data)"
                    />
                    <Button
                      v-if="canDelete && !data.es_sistema"
                      icon="pi pi-trash"
                      text
                      rounded
                      severity="danger"
                      :aria-label="t('common.delete')"
                      @click="deleteCriterio = data"
                    />
                  </div>
                </template>
              </Column>
            </DataTable>
          </div>
        </TabPanel>
      </TabPanels>
    </Tabs>

    <Dialog
      v-model:visible="catDialogVisible"
      modal
      :header="editingCategoria ? t('events.wizard.catEdit') : t('events.wizard.catAdd')"
      :style="{ width: 'min(34rem, 94vw)' }"
    >
      <div class="form-grid">
        <div class="field field--full">
          <label>{{ t('events.wizard.catName') }}</label>
          <InputText v-model="catForm.nombre" class="w-full" />
        </div>
        <div class="field">
          <label>{{ t('events.wizard.subVisualPick') }}</label>
          <IconColorPopover v-model:icono="catForm.icono" v-model:color="catForm.color" />
        </div>
        <div class="field">
          <label>{{ t('events.wizard.catOrder') }}</label>
          <InputNumber v-model="catForm.orden" class="w-full" :min="0" />
        </div>
        <div class="field field--row field--full">
          <label>{{ t('events.wizard.catActive') }}</label>
          <ToggleSwitch v-model="catForm.estado" />
        </div>
        <div class="field field--full caps">
          <h4>{{ t('events.wizard.catCapsTitle') }}</h4>
          <p class="pj-muted">{{ t('events.wizard.catCapsLead') }}</p>
          <label class="cap-row">
            <ToggleSwitch v-model="catForm.maneja_puntos" />
            <span>{{ t('events.wizard.catFlagPoints') }}</span>
          </label>
          <label class="cap-row">
            <ToggleSwitch v-model="catForm.maneja_fecha_inicio" />
            <span>{{ t('events.wizard.catFlagStart') }}</span>
          </label>
          <label class="cap-row">
            <ToggleSwitch v-model="catForm.maneja_fecha_fin" />
            <span>{{ t('events.wizard.catFlagEnd') }}</span>
          </label>
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="catDialogVisible = false" />
        <Button :label="t('common.save')" :loading="saving" @click="saveCategoria" />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="critDialogVisible"
      modal
      :header="editingCriterio ? t('events.wizard.criteriaAdminTitle') : t('events.wizard.criteriaCreate')"
      :style="{ width: 'min(34rem, 94vw)' }"
    >
      <div class="form-grid">
        <div class="field field--full">
          <label>{{ t('events.wizard.criteriaName') }}</label>
          <InputText v-model="critForm.nombre" class="w-full" />
        </div>
        <div class="field field--full">
          <label>{{ t('events.wizard.criteriaDesc') }}</label>
          <Textarea v-model="critForm.descripcion" rows="3" class="w-full" auto-resize />
        </div>
        <div class="field">
          <label>{{ t('events.wizard.subVisualPick') }}</label>
          <IconColorPopover v-model:icono="critForm.icono" v-model:color="critForm.color" />
        </div>
        <div class="field">
          <label>{{ t('events.wizard.catOrder') }}</label>
          <InputNumber v-model="critForm.orden" class="w-full" :min="0" />
        </div>
        <div class="field field--row">
          <label>{{ t('common.active') }}</label>
          <ToggleSwitch v-model="critForm.estado" />
        </div>
      </div>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="critDialogVisible = false" />
        <Button :label="t('common.save')" :loading="saving" @click="saveCriterio" />
      </template>
    </Dialog>

    <Dialog
      :visible="deleteCategoria !== null"
      modal
      :header="t('common.confirm')"
      :style="{ width: 'min(26rem, 94vw)' }"
      @update:visible="(v: boolean) => !v && (deleteCategoria = null)"
    >
      <p>{{ t('events.wizard.catDeleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteCategoria = null" />
        <Button
          :label="t('common.delete')"
          severity="danger"
          :loading="deleting"
          @click="confirmDeleteCategoria"
        />
      </template>
    </Dialog>

    <Dialog
      :visible="deleteCriterio !== null"
      modal
      :header="t('common.confirm')"
      :style="{ width: 'min(26rem, 94vw)' }"
      @update:visible="(v: boolean) => !v && (deleteCriterio = null)"
    >
      <p>{{ t('events.catalogos.criteriaDeleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteCriterio = null" />
        <Button
          :label="t('common.delete')"
          severity="danger"
          :loading="deleting"
          @click="confirmDeleteCriterio"
        />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.lead {
  margin: 0.85rem 0 1rem;
}

.name-cell {
  display: flex;
  align-items: flex-start;
  gap: 0.7rem;
}

.name-cell .pj-muted {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.82rem;
}

.cat-icon {
  width: 2rem;
  height: 2rem;
  display: grid;
  place-items: center;
  border-radius: 8px;
  border: 1px solid transparent;
  flex-shrink: 0;
}

.flags {
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin-top: 0.25rem;
}

.flag {
  font-size: 0.72rem;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  border: 1px solid color-mix(in srgb, var(--pj-border) 80%, transparent);
  color: var(--pj-text-muted);
}

.actions {
  display: flex;
  gap: 0.15rem;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.85rem 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.field--full {
  grid-column: 1 / -1;
}

.field--row {
  flex-direction: row;
  align-items: center;
  justify-content: space-between;
}

.caps h4 {
  margin: 0 0 0.25rem;
  font-size: 0.95rem;
}

.caps .pj-muted {
  margin: 0 0 0.55rem;
  font-size: 0.85rem;
}

.cap-row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  cursor: pointer;
  margin-bottom: 0.45rem;
}

.w-full {
  width: 100%;
}

@media (max-width: 640px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
}
</style>
