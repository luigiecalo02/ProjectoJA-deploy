<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import PageLoader from '@/components/PageLoader.vue'
import AppSearchField from '@/components/AppSearchField.vue'
import { personasService } from '@/services/clubsService'
import { getApiErrorMessage } from '@/services/api'
import { usePermission } from '@/composables/usePermission'
import { useAuthStore } from '@/stores/auth'
import type { Persona } from '@/modules/clubs/types'
import type { PaginationMeta } from '@/types/api'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const { can } = usePermission()
const auth = useAuthStore()

const personas = ref<Persona[]>([])
const loading = ref(false)
const pagination = ref<PaginationMeta | null>(null)
const deleteTarget = ref<Persona | null>(null)
const deleting = ref(false)

const contextOrgLabel = computed(() => {
  const ctx = auth.contexto
  if (!ctx?.organizacion_nombre) return null
  return ctx.is_platform
    ? null
    : `${ctx.organizacion_nombre}${ctx.rol_display_name ? ` · ${ctx.rol_display_name}` : ''}`
})

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
    const result = await personasService.list({
      page: filters.page,
      per_page: filters.per_page,
      search: filters.search || undefined,
    })
    personas.value = result.items
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
    await personasService.remove(deleteTarget.value.id)
    toast.add({ severity: 'success', summary: t('common.success'), detail: t('personas.deleteSuccess'), life: 2500 })
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
        <h1 class="pj-page__title">{{ t('personas.title') }}</h1>
        <p class="pj-page__subtitle">
          {{ contextOrgLabel ? t('personas.subtitleScoped', { org: contextOrgLabel }) : t('personas.subtitle') }}
        </p>
      </div>
      <Button
        v-if="can('personas.create')"
        icon="pi pi-plus"
        :label="t('personas.new')"
        @click="router.push({ name: 'personas.create' })"
      />
    </header>

    <div class="pj-panel">
      <PageLoader v-if="loading && !personas.length" :label="t('common.loading')" />
      <template v-else>
        <div class="pj-toolbar" style="margin-bottom: 0.75rem">
          <AppSearchField v-model="filters.search" :placeholder="t('personas.searchPlaceholder')" />
        </div>

        <DataTable
          :value="personas"
          data-key="id"
          striped-rows
          lazy
          paginator
          :rows="filters.per_page"
          :total-records="pagination?.total ?? personas.length"
          :first="((pagination?.current_page ?? 1) - 1) * (pagination?.per_page ?? filters.per_page)"
          :loading="loading"
          @page="onPage"
        >
          <template #empty>
            <p class="pj-muted">{{ t('personas.empty') }}</p>
          </template>

          <Column :header="t('personas.fullName')" field="full_name" />
          <Column :header="t('personas.idNumber')">
            <template #body="{ data }">
              {{ data.tipo_identificacion }} {{ data.identificacion }}
            </template>
          </Column>
          <Column :header="t('personas.organizaciones')">
            <template #body="{ data }">
              <div
                v-if="(data.organizaciones ?? []).filter((o) => o.estado !== false).length"
                class="orgs-cell"
              >
                <Tag
                  v-for="org in (data.organizaciones ?? []).filter((o) => o.estado !== false)"
                  :key="org.id"
                  severity="info"
                  :value="org.organizacion_nombre || `#${org.organizacion_id}`"
                />
              </div>
              <span v-else class="pj-muted">{{ t('personas.noOrganizaciones') }}</span>
            </template>
          </Column>
          <Column field="correo" :header="t('personas.email')">
            <template #body="{ data }">{{ data.correo || '—' }}</template>
          </Column>
          <Column field="telefono" :header="t('personas.phone')">
            <template #body="{ data }">{{ data.telefono || '—' }}</template>
          </Column>
          <Column :header="t('common.actions')" style="width: 8rem">
            <template #body="{ data }">
              <div class="actions">
                <Button
                  v-if="can('personas.update')"
                  icon="pi pi-pencil"
                  text
                  rounded
                  @click="router.push({ name: 'personas.edit', params: { id: data.id } })"
                />
                <Button
                  v-if="can('personas.delete')"
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
      </template>
    </div>

    <Dialog v-model:visible="deleteDialogVisible" modal :header="t('common.confirm')" :style="{ width: '28rem' }">
      <p>{{ t('personas.deleteConfirm') }}</p>
      <template #footer>
        <Button :label="t('common.cancel')" text @click="deleteTarget = null" />
        <Button :label="t('common.delete')" severity="danger" :loading="deleting" @click="confirmDelete" />
      </template>
    </Dialog>
  </section>
</template>

<style scoped>
.search { min-width: min(100%, 18rem); }
.actions { display: flex; gap: 0.1rem; }
.orgs-cell { display: flex; flex-wrap: wrap; gap: 0.3rem; }
</style>
