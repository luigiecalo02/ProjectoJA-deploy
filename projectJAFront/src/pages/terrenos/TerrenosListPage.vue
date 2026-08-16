<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import { terrenosService } from '@/services/terrenosService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { Terreno } from '@/modules/terrenos/types'
import type { PaginationMeta } from '@/types/api'
import { DEFAULT_MAP_CENTER, DEFAULT_MAP_ZOOM } from '@/modules/terrenos/geometria'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const items = ref<Terreno[]>([])
const loading = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<Terreno | null>(null)
const deleting = ref(false)
const creating = ref(false)
const createVisible = ref(false)
const newNombre = ref('')

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (v: boolean) => {
    if (!v) deleteTarget.value = null
  },
})

const filters = reactive({ search: '', page: 1, per_page: 10 })

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await terrenosService.list({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search || undefined,
    })
    items.value = result.items
    pagination.value = result.pagination
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

function onPage(event: { page: number; rows: number }): void {
  filters.page = event.page + 1
  filters.per_page = event.rows
  void load()
}

async function confirmDelete(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await terrenosService.remove(deleteTarget.value.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('terrenos.deleteSuccess'), life: 2500 })
    deleteTarget.value = null
    await load()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    deleting.value = false
  }
}

async function createTerreno(): Promise<void> {
  if (!newNombre.value.trim()) return
  creating.value = true
  try {
    const terreno = await terrenosService.create({
      nombre: newNombre.value.trim(),
      metros_por_persona: 10,
      estado: 'activo',
      latitud: DEFAULT_MAP_CENTER.lat,
      longitud: DEFAULT_MAP_CENTER.lng,
      nivel_zoom: DEFAULT_MAP_ZOOM,
    })
    createVisible.value = false
    newNombre.value = ''
    await router.push({ name: 'terrenos.map', params: { id: terreno.id } })
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    creating.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(
  () => filters.search,
  () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
      filters.page = 1
      void load()
    }, 300)
  },
)

onMounted(() => void load())
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-display">{{ t('terrenos.title') }}</h1>
        <p>{{ t('terrenos.subtitle') }}</p>
      </div>
      <Button
        v-if="can('terrenos.create')"
        :label="t('terrenos.create')"
        icon="pi pi-plus"
        @click="createVisible = true"
      />
    </header>

    <div class="pj-toolbar">
      <AppSearchField v-model="filters.search" :placeholder="t('common.search')" />
    </div>

    <PageLoader v-if="loading && !items.length" />
    <DataTable
      v-else
      :value="items"
      :lazy="true"
      :paginator="true"
      :rows="filters.per_page"
      :total-records="pagination?.total ?? 0"
      :first="(filters.page - 1) * filters.per_page"
      data-key="id"
      @page="onPage"
    >
      <Column field="nombre" :header="t('terrenos.nombre')" />
      <Column :header="t('terrenos.configuraciones')">
        <template #body="{ data }">{{ data.configuraciones_count ?? 0 }}</template>
      </Column>
      <Column :header="t('terrenos.area')">
        <template #body="{ data }">{{ data.area_total ? `${data.area_total} m²` : '—' }}</template>
      </Column>
      <Column :header="t('terrenos.estadoLabel')">
        <template #body="{ data }">
          <Tag :value="data.estado" :severity="data.estado === 'activo' ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              icon="pi pi-map"
              text
              rounded
              :aria-label="t('terrenos.openMap')"
              @click="router.push({ name: 'terrenos.map', params: { id: data.id } })"
            />
            <Button
              v-if="can('terrenos.delete')"
              icon="pi pi-trash"
              text
              rounded
              severity="danger"
              @click="deleteTarget = data"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <Dialog v-model:visible="createVisible" modal :header="t('terrenos.create')" :style="{ width: 'min(400px, 95vw)' }">
      <label class="create-field">
        <span>{{ t('terrenos.nombre') }}</span>
        <InputText v-model="newNombre" autofocus />
      </label>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="createVisible = false" />
        <Button :label="t('common.create')" :loading="creating" :disabled="!newNombre.trim()" @click="createTerreno" />
      </template>
    </Dialog>

    <Dialog v-model:visible="deleteDialogVisible" modal :header="t('common.confirm')" :style="{ width: 'min(400px, 95vw)' }">
      <p>{{ t('terrenos.deleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteTarget = null" />
        <Button :label="t('common.delete')" severity="danger" :loading="deleting" @click="confirmDelete" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.row-actions {
  display: flex;
  gap: 0.25rem;
}

.create-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
</style>
