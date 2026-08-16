<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import AppSearchField from '@/components/AppSearchField.vue'
import AppStackDrawer from '@/components/drawers/AppStackDrawer.vue'
import PageLoader from '@/components/PageLoader.vue'
import { cabanasService } from '@/services/cabanasService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import type { Cabana, CabanaEstado } from '@/modules/cabanas/types'
import type { PaginationMeta } from '@/types/api'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()
const items = ref<Cabana[]>([])
const pagination = ref<PaginationMeta | null>(null)
const loading = ref(true)
const saving = ref(false)
const deleting = ref(false)
const drawerVisible = ref(false)
const editTarget = ref<Cabana | null>(null)
const deleteTarget = ref<Cabana | null>(null)
const filters = reactive({ search: '', page: 1, per_page: 10 })
const form = reactive({ nombre: '', descripcion: '', estado: 'activa' as CabanaEstado })
const estadoOptions = computed(() => [
  { label: t('cabanas.active'), value: 'activa' },
  { label: t('cabanas.inactive'), value: 'inactiva' },
])
const deleteVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (value: boolean) => {
    if (!value) deleteTarget.value = null
  },
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await cabanasService.list({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search,
    })
    items.value = result.items
    pagination.value = result.pagination
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editTarget.value = null
  Object.assign(form, { nombre: '', descripcion: '', estado: 'activa' })
  drawerVisible.value = true
}

function openEdit(item: Cabana): void {
  editTarget.value = item
  Object.assign(form, {
    nombre: item.nombre,
    descripcion: item.descripcion ?? '',
    estado: item.estado,
  })
  drawerVisible.value = true
}

async function save(): Promise<void> {
  if (!form.nombre.trim()) return
  saving.value = true
  try {
    const payload = {
      nombre: form.nombre.trim(),
      descripcion: form.descripcion.trim() || null,
      estado: form.estado,
    }
    if (editTarget.value) await cabanasService.update(editTarget.value.id, payload)
    else await cabanasService.create(payload)
    drawerVisible.value = false
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.saveSuccess'), life: 2500 })
    await load()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  if (!deleteTarget.value) return
  deleting.value = true
  try {
    await cabanasService.remove(deleteTarget.value.id)
    deleteTarget.value = null
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('cabanas.deleteSuccess'), life: 2500 })
    await load()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    deleting.value = false
  }
}

function onPage(event: { page: number; rows: number }): void {
  filters.page = event.page + 1
  filters.per_page = event.rows
  void load()
}

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(() => filters.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.page = 1
    void load()
  }, 300)
})

onMounted(() => void load())
</script>

<template>
  <section class="pj-page">
    <header class="pj-page__header">
      <div>
        <h1 class="pj-display">{{ t('cabanas.title') }}</h1>
        <p>{{ t('cabanas.subtitle') }}</p>
      </div>
      <Button v-if="can('cabanas.create')" :label="t('cabanas.create')" icon="pi pi-plus" @click="openCreate" />
    </header>

    <div class="pj-toolbar">
      <AppSearchField v-model="filters.search" :placeholder="t('cabanas.search')" />
    </div>

    <PageLoader v-if="loading && !items.length" />
    <DataTable
      v-else
      :value="items"
      lazy
      paginator
      :rows="filters.per_page"
      :total-records="pagination?.total ?? items.length"
      :first="(filters.page - 1) * filters.per_page"
      data-key="id"
      @page="onPage"
    >
      <Column field="nombre" :header="t('cabanas.name')">
        <template #body="{ data }">
          <strong>{{ data.nombre }}</strong>
          <small class="description">{{ data.descripcion || '—' }}</small>
        </template>
      </Column>
      <Column :header="t('cabanas.floors')">
        <template #body="{ data }">{{ data.pisos_count ?? data.pisos?.length ?? 0 }}</template>
      </Column>
      <Column :header="t('cabanas.rooms')">
        <template #body="{ data }">{{ data.cuartos_count ?? 0 }}</template>
      </Column>
      <Column :header="t('cabanas.capacity')">
        <template #body="{ data }">{{ data.capacidad_total ?? 0 }}</template>
      </Column>
      <Column :header="t('cabanas.status')">
        <template #body="{ data }">
          <Tag :value="data.estado" :severity="data.estado === 'activa' ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              v-if="can('cabanas.view')"
              icon="pi pi-map"
              text
              rounded
              :aria-label="t('cabanas.openLayout')"
              @click="router.push({ name: 'cabanas.layout', params: { id: data.id } })"
            />
            <Button v-if="can('cabanas.update')" icon="pi pi-pencil" text rounded @click="openEdit(data)" />
            <Button
              v-if="can('cabanas.delete')"
              icon="pi pi-trash"
              severity="danger"
              text
              rounded
              @click="deleteTarget = data"
            />
          </div>
        </template>
      </Column>
    </DataTable>

    <AppStackDrawer
      v-model:visible="drawerVisible"
      :title="editTarget ? t('cabanas.edit') : t('cabanas.create')"
      :subtitle="t('cabanas.formHint')"
      :level="1"
    >
      <label>{{ t('cabanas.name') }} *<InputText v-model="form.nombre" autofocus /></label>
      <label>{{ t('cabanas.description') }}<Textarea v-model="form.descripcion" rows="4" /></label>
      <label>
        {{ t('cabanas.status') }}
        <Select v-model="form.estado" :options="estadoOptions" option-label="label" option-value="value" />
      </label>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="drawerVisible = false" />
        <Button :label="t('common.save')" icon="pi pi-save" :loading="saving" :disabled="!form.nombre.trim()" @click="save" />
      </template>
    </AppStackDrawer>

    <Dialog v-model:visible="deleteVisible" modal :header="t('common.confirm')" :style="{ width: 'min(420px, 95vw)' }">
      <p>{{ t('cabanas.deleteConfirm', { name: deleteTarget?.nombre }) }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteTarget = null" />
        <Button :label="t('common.delete')" severity="danger" :loading="deleting" @click="remove" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.description { display: block; max-width: 30rem; margin-top: .2rem; color: var(--pj-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.row-actions { display: flex; gap: .2rem; }
:deep(.stack-drawer__body label) { display: grid; gap: .35rem; font-size: .86rem; font-weight: 600; }
:deep(.stack-drawer__body .p-inputtext), :deep(.stack-drawer__body .p-select), :deep(.stack-drawer__body .p-textarea) { width: 100%; }
</style>
