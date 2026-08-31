<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import { lugaresService } from '@/services/lugaresService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { usePageChrome } from '@/composables/usePageChrome'
import type { Lugar } from '@/modules/lugares/types'
import type { PaginationMeta } from '@/types/api'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()

const items = ref<Lugar[]>([])
const loading = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<Lugar | null>(null)
const deleting = ref(false)
const filters = reactive({ search: '', page: 1, per_page: 10 })

usePageChrome(() => ({
  title: t('lugares.title'),
  subtitle: t('lugares.subtitle'),
  actions: can('lugares.create')
    ? [
        {
          key: 'new',
          label: t('lugares.create'),
          icon: 'pi pi-plus',
          onClick: () => router.push({ name: 'lugares.form' }),
        },
      ]
    : [],
}))

const deleteDialogVisible = computed({
  get: () => deleteTarget.value !== null,
  set: (v: boolean) => {
    if (!v) deleteTarget.value = null
  },
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await lugaresService.list({
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
    await lugaresService.remove(deleteTarget.value.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('lugares.deleteSuccess'), life: 2500 })
    deleteTarget.value = null
    await load()
  } catch (error) {
    toast.add({ severity: 'error', summary: t('common.error'), detail: getApiErrorMessage(error), life: 4000 })
  } finally {
    deleting.value = false
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
        <h1 class="pj-display">{{ t('lugares.title') }}</h1>
        <p>{{ t('lugares.subtitle') }}</p>
      </div>
      <Button
        v-if="can('lugares.create')"
        :label="t('lugares.create')"
        icon="pi pi-plus"
        @click="router.push({ name: 'lugares.form' })"
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
      <Column field="nombre" :header="t('lugares.nombre')" />
      <Column :header="t('lugares.coords')">
        <template #body="{ data }">
          <span v-if="data.latitud != null && data.longitud != null">
            {{ data.latitud.toFixed(5) }}, {{ data.longitud.toFixed(5) }}
          </span>
          <span v-else>—</span>
        </template>
      </Column>
      <Column :header="t('terrenos.title')">
        <template #body="{ data }">{{ data.terrenos_count ?? 0 }}</template>
      </Column>
      <Column :header="t('cabanas.title')">
        <template #body="{ data }">{{ data.cabanas_count ?? 0 }}</template>
      </Column>
      <Column :header="t('lugares.estado')">
        <template #body="{ data }">
          <Tag :value="data.estado" :severity="data.estado === 'activo' ? 'success' : 'secondary'" />
        </template>
      </Column>
      <Column :header="t('common.actions')">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              icon="pi pi-pencil"
              text
              rounded
              @click="router.push({ name: 'lugares.edit', params: { id: data.id } })"
            />
            <Button
              v-if="can('lugares.delete')"
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

    <Dialog v-model:visible="deleteDialogVisible" modal :header="t('common.confirm')" :style="{ width: 'min(400px, 95vw)' }">
      <p>{{ t('lugares.deleteConfirm') }}</p>
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
</style>
